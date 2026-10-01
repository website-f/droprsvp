<?php

namespace Tests\Feature\Edm;

use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailSend;
use App\Models\Event;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Edm\CampaignSender;
use App\Support\Edm\Audience;
use App\Support\Edm\Consent;
use App\Support\Edm\Throttle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The send pipeline: who gets it, how fast, and when it stops itself.
 *
 * Built for shared hosting — the send table is the queue and the scheduler is
 * the worker — so these drive dispatch() directly, one "minute" at a time.
 */
class CampaignSendingTest extends TestCase
{
    use RefreshDatabase;

    private CampaignSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = app(CampaignSender::class);
        // Generous by default so tests that are not about throttling are not
        // throttled; the throttle tests set their own.
        config(['edm.hourly_limit' => 6000, 'edm.warmup.enabled' => false]);
    }

    private function subscriber(string $email, array $user = []): User
    {
        $u = User::factory()->create(['email' => $email, ...$user]);
        Consent::grant($email, 'register', $u);

        return $u;
    }

    private function campaign(array $attributes = []): EmailCampaign
    {
        return EmailCampaign::create(array_merge([
            'name' => 'October line-up',
            'subject' => 'Hi {{first_name}}, this weekend',
            'design' => ['root' => ['props' => []], 'content' => [
                ['type' => 'Text', 'props' => ['html' => '<p>Hello {{first_name}}</p>']],
                ['type' => 'Button', 'props' => ['label' => 'See events', 'url' => 'https://droprsvp.test/en-my/all/']],
            ]],
        ], $attributes));
    }

    // ---- audience ------------------------------------------------------------

    public function test_only_subscribed_unsuppressed_addresses_are_in_the_audience(): void
    {
        $this->subscriber('in@example.test');
        User::factory()->create(['email' => 'never-asked@example.test']);
        $this->subscriber('left@example.test');
        Consent::revoke('left@example.test');
        $this->subscriber('bounced@example.test');
        Consent::suppress('bounced@example.test', 'bounce');

        $this->assertSame(['in@example.test'], Audience::query([])->pluck('email')->all());
    }

    public function test_filters_narrow_and_never_widen(): void
    {
        $this->subscriber('kl@example.test', ['city' => 'Kuala Lumpur']);
        $this->subscriber('ipoh@example.test', ['city' => 'Ipoh']);

        $this->assertSame(1, Audience::count(['cities' => ['Kuala Lumpur']]));
        $this->assertSame(0, Audience::count(['cities' => ['Melaka']]));
    }

    public function test_purchase_filters_match_guest_orders_by_email_whatever_its_case(): void
    {
        // Orders store the address as typed; consent stores it normalised.
        $this->subscriber('buyer@example.test');
        $this->subscriber('looker@example.test');

        $event = Event::create([
            'user_id' => User::factory()->create()->id, 'title' => 'Gig', 'slug' => 'gig',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
        Order::create([
            'reference' => 'R1', 'event_id' => $event->id, 'status' => 'paid', 'total' => 10,
            'currency' => 'MYR', 'buyer_email' => 'Buyer@Example.TEST', 'paid_at' => now()->subDays(5),
        ]);

        $this->assertSame(['buyer@example.test'], Audience::query(['purchase' => 'buyers'])->pluck('email')->all());
        $this->assertSame(['looker@example.test'], Audience::query(['purchase' => 'non_buyers'])->pluck('email')->all());
        $this->assertSame(1, Audience::count(['event_ids' => [$event->id]]));
        $this->assertSame(1, Audience::count(['purchased_within_days' => 30]));
        $this->assertSame(0, Audience::count(['purchased_within_days' => 2]));
    }

    // ---- starting -------------------------------------------------------------

    public function test_starting_freezes_the_content_and_queues_one_send_per_person(): void
    {
        $this->subscriber('a@example.test');
        $this->subscriber('b@example.test');

        $campaign = $this->sender->start($this->campaign());

        $this->assertSame('sending', $campaign->status);
        $this->assertSame(2, $campaign->recipients_count);
        $this->assertSame(2, EmailSend::where('status', 'queued')->count());
        // Links registered once for the campaign, replaced by placeholders.
        $this->assertStringContainsString('{{click:', $campaign->html);
        $this->assertSame(1, $campaign->links()->count());
        // Every send has its own unguessable token.
        $this->assertSame(2, EmailSend::distinct('token')->count('token'));
    }

    public function test_a_campaign_with_nobody_to_send_to_finishes_straight_away(): void
    {
        $campaign = $this->sender->start($this->campaign());

        $this->assertSame('sent', $campaign->status);
        $this->assertSame(0, $campaign->recipients_count);
    }

    public function test_a_campaign_without_a_subject_or_content_cannot_start(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->sender->start($this->campaign(['subject' => ' ']));
    }

    // ---- sending --------------------------------------------------------------

    public function test_dispatch_sends_personalised_mail_with_one_click_unsubscribe(): void
    {
        Mail::fake();
        $this->subscriber('aisyah@example.test', ['name' => 'Aisyah Rahman']);

        $this->sender->start($this->campaign());
        $this->assertSame(1, $this->sender->dispatch());

        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
            $headers = $mail->headers()->text;

            return $mail->hasTo('aisyah@example.test')
                && $mail->subjectLine === 'Hi Aisyah, this weekend'
                && str_contains($mail->htmlBody, 'Hello Aisyah')
                // Tracked link, open pixel, unsubscribe — all by token only.
                && str_contains($mail->htmlBody, '/m/c/')
                && str_contains($mail->htmlBody, '/m/o/')
                && ! str_contains($mail->htmlBody, 'aisyah@example.test')
                && str_starts_with($headers['List-Unsubscribe'], '<'.url('/m/u/'))
                && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
        });

        $send = EmailSend::first();
        $this->assertSame('sent', $send->status);
        $this->assertNotNull($send->sent_at);
        $this->assertSame(1, $send->campaign->sent_count);
    }

    public function test_campaign_mail_goes_through_its_own_mailer(): void
    {
        Mail::fake();
        $this->subscriber('a@example.test');

        $this->sender->start($this->campaign());
        $this->sender->dispatch();

        Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->mailer === 'edm');
    }

    public function test_someone_who_unsubscribes_after_queueing_is_skipped(): void
    {
        Mail::fake();
        $this->subscriber('changed-mind@example.test');

        $this->sender->start($this->campaign());
        Consent::revoke('changed-mind@example.test');
        $this->sender->dispatch();

        Mail::assertNothingSent();
        $this->assertSame('skipped', EmailSend::first()->status);
    }

    public function test_the_campaign_finishes_once_nothing_is_left_queued(): void
    {
        Mail::fake();
        $this->subscriber('a@example.test');

        $campaign = $this->sender->start($this->campaign());
        $this->sender->dispatch();

        $this->assertSame('sent', $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->finished_at);
    }

    public function test_a_scheduled_campaign_starts_when_its_time_comes(): void
    {
        Mail::fake();
        $this->subscriber('a@example.test');

        $campaign = $this->sender->schedule($this->campaign(), now()->addHour());
        $this->sender->dispatch();
        $this->assertSame('scheduled', $campaign->fresh()->status);

        $this->travel(61)->minutes();
        $this->sender->dispatch();

        Mail::assertSent(CampaignMail::class, 1);
    }

    public function test_cancelling_skips_everything_not_yet_sent(): void
    {
        Mail::fake();
        $this->subscriber('a@example.test');
        $this->subscriber('b@example.test');

        $campaign = $this->sender->start($this->campaign());
        $this->sender->cancel($campaign);
        $this->sender->dispatch();

        Mail::assertNothingSent();
        $this->assertSame(2, EmailSend::where('status', 'skipped')->count());
        $this->assertSame('cancelled', $campaign->fresh()->status);
    }

    public function test_a_paused_campaign_sends_nothing_until_resumed(): void
    {
        Mail::fake();
        $this->subscriber('a@example.test');

        $campaign = $this->sender->start($this->campaign());
        $this->sender->pause($campaign);
        $this->sender->dispatch();
        Mail::assertNothingSent();

        $this->sender->resume($campaign->fresh());
        $this->sender->dispatch();
        Mail::assertSent(CampaignMail::class, 1);
    }

    // ---- throttling -----------------------------------------------------------

    public function test_sending_is_spread_across_the_hour(): void
    {
        // 120 an hour is 2 a minute: a burst of 120 in the first minute is
        // exactly what spam looks like.
        Mail::fake();
        config(['edm.hourly_limit' => 120]);
        foreach (range(1, 5) as $i) {
            $this->subscriber("p{$i}@example.test");
        }

        $this->sender->start($this->campaign());

        $this->assertSame(2, $this->sender->dispatch());
        $this->assertSame(3, EmailSend::where('status', 'queued')->count());
    }

    public function test_the_hourly_limit_is_never_exceeded(): void
    {
        Mail::fake();
        config(['edm.hourly_limit' => 60]);
        foreach (range(1, 3) as $i) {
            $this->subscriber("p{$i}@example.test");
        }

        $campaign = $this->sender->start($this->campaign());
        // 60 already went out in the last hour, from anywhere.
        foreach (range(1, 60) as $i) {
            EmailSend::create(['campaign_id' => $campaign->id, 'email' => "old{$i}@example.test", 'token' => str_pad((string) $i, 40, 'x'), 'status' => 'sent', 'sent_at' => now()->subMinutes(30)]);
        }

        $this->assertSame(0, Throttle::budget());
        $this->assertSame(0, $this->sender->dispatch());
    }

    public function test_an_admin_set_limit_overrides_the_config(): void
    {
        Setting::putArray('edm', ['hourly_limit' => 300]);

        $this->assertSame(300, Throttle::hourlyLimit());
        $this->assertSame(5, Throttle::perMinute());
    }

    public function test_warm_up_starts_small_and_doubles_on_schedule(): void
    {
        config(['edm.warmup.enabled' => true, 'edm.warmup.start_per_day' => 100, 'edm.warmup.double_every_days' => 3, 'edm.hourly_limit' => 1000]);

        // Nothing ever sent: the new address starts at the bottom.
        $this->assertSame(100, Throttle::warmupDailyCap());

        $campaign = $this->campaign();
        EmailSend::create(['campaign_id' => $campaign->id, 'email' => 'first@example.test', 'token' => str_repeat('a', 40), 'status' => 'sent', 'sent_at' => now()]);

        $this->travel(3)->days();
        $this->assertSame(200, Throttle::warmupDailyCap());

        $this->travel(3)->days();
        $this->assertSame(400, Throttle::warmupDailyCap());
    }

    // ---- stopping itself ------------------------------------------------------

    public function test_a_server_that_keeps_refusing_mail_pauses_the_campaign(): void
    {
        // A real SMTP connection to a port nothing listens on: refused at once.
        config([
            'mail.mailers.edm' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2],
            'edm.auto_pause.consecutive_failures' => 3,
        ]);
        // Someone to tell: the alert goes to superadmins.
        Role::findOrCreate('superadmin', 'web');
        User::factory()->create()->assignRole('superadmin');
        foreach (range(1, 5) as $i) {
            $this->subscriber("p{$i}@example.test");
        }

        $campaign = $this->sender->start($this->campaign());
        $this->sender->dispatch();

        $campaign->refresh();
        $this->assertSame('paused', $campaign->status);
        $this->assertStringContainsString('3 sending failures in a row', $campaign->paused_reason);
        // Not given up on: left queued, to retry once someone resumes it.
        $this->assertSame(5, EmailSend::where('status', 'queued')->count());
        $this->assertDatabaseHas('app_notifications', ['type' => 'edm']);
    }

    public function test_a_high_bounce_rate_pauses_the_campaign(): void
    {
        Mail::fake();
        config(['edm.auto_pause.min_sent' => 50, 'edm.auto_pause.bounce_rate' => 0.05]);
        $this->subscriber('still-queued@example.test');

        $campaign = $this->sender->start($this->campaign());
        $campaign->forceFill(['sent_count' => 100, 'bounced_count' => 9])->save();

        $this->sender->dispatch();

        $this->assertSame('paused', $campaign->fresh()->status);
        $this->assertStringContainsString('Bounce rate 9.0%', $campaign->fresh()->paused_reason);
        // Checked BEFORE the batch, so a campaign already over the line does
        // not send one more round on its way to stopping. (The admin alert
        // about the pause is mail too, and is expected.)
        Mail::assertNotSent(CampaignMail::class);
    }
}
