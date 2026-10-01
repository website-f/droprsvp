<?php

namespace Tests\Feature\Edm;

use App\Http\Controllers\Admin\EdmCampaignController;
use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailSend;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\Edm\CampaignSender;
use App\Support\Edm\Audience;
use App\Support\Edm\Consent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The one-off "may we email you?" message to existing users.
 *
 * Nobody on the platform ever opted in to marketing, so this is how the list
 * is built from people we already know — and it is only defensible if it asks
 * once, asks only people who have not already answered, and records a yes only
 * when a person actually gives one.
 */
class RepermissionTest extends TestCase
{
    use RefreshDatabase;

    private CampaignSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        config(['edm.hourly_limit' => 60000, 'edm.warmup.enabled' => false]);
        $this->sender = app(CampaignSender::class);
    }

    private function campaign(): EmailCampaign
    {
        return EmailCampaign::create([
            'name' => 'Ask',
            'kind' => 'repermission',
            'subject' => 'Can we keep you posted?',
            'design' => EdmCampaignController::repermissionDesign(),
        ]);
    }

    private function guestBuyer(string $email): void
    {
        $event = Event::firstOrCreate(['slug' => 'gig'], [
            'user_id' => User::factory()->create()->id, 'title' => 'Gig',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
        Order::create([
            'reference' => 'R-'.uniqid(), 'event_id' => $event->id, 'status' => 'paid', 'total' => 10,
            'currency' => 'MYR', 'buyer_email' => $email, 'buyer_name' => 'Guest Buyer', 'paid_at' => now(),
        ]);
    }

    // ---- who is asked ----------------------------------------------------------

    public function test_accounts_and_guest_buyers_who_never_chose_are_asked(): void
    {
        User::factory()->create(['email' => 'member@example.test']);
        $this->guestBuyer('Guest@Example.TEST');

        $emails = Audience::repermission()->pluck('email')->sort()->values()->all();

        // The organizer behind the event has an account too.
        $this->assertContains('member@example.test', $emails);
        // Matched case-insensitively, so one person is one row.
        $this->assertContains('guest@example.test', $emails);
    }

    public function test_someone_who_already_chose_either_way_is_not_asked(): void
    {
        User::factory()->create(['email' => 'yes@example.test']);
        User::factory()->create(['email' => 'no@example.test']);
        Consent::grant('yes@example.test', 'register');
        Consent::revoke('no@example.test');

        $emails = Audience::repermission()->pluck('email')->all();

        $this->assertNotContains('yes@example.test', $emails);
        // Asking someone who said no would ignore the answer they gave.
        $this->assertNotContains('no@example.test', $emails);
    }

    public function test_a_suppressed_address_is_not_asked(): void
    {
        User::factory()->create(['email' => 'dead@example.test']);
        Consent::suppress('dead@example.test', 'bounce');

        $this->assertNotContains('dead@example.test', Audience::repermission()->pluck('email')->all());
    }

    public function test_a_person_with_an_account_and_orders_is_asked_once(): void
    {
        $user = User::factory()->create(['email' => 'both@example.test']);
        $this->guestBuyer('both@example.test');

        $this->assertSame(1, Audience::repermission()->get()->where('email', 'both@example.test')->count());
    }

    public function test_nobody_is_ever_asked_twice(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'once@example.test']);

        $this->sender->start($this->campaign());
        $this->sender->dispatch();

        // A second re-permission campaign, later.
        $second = $this->sender->start($this->campaign());

        $this->assertSame(0, $second->sends()->where('email', 'once@example.test')->count());
    }

    public function test_a_large_list_is_queued_without_skipping_anyone(): void
    {
        // The list is inserted 500 at a time while "never asked" is being
        // evaluated. If the campaign's own rows counted as "asked", each chunk
        // would vanish from the query and the next page would skip people.
        foreach (range(1, 1205) as $i) {
            $rows[] = ['name' => "P{$i}", 'email' => sprintf('p%04d@example.test', $i), 'password' => 'x', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            User::insert($chunk);
        }

        $campaign = $this->sender->start($this->campaign());

        $this->assertSame(1205, $campaign->recipients_count);
        $this->assertSame(1205, EmailSend::distinct()->count('email'));
    }

    // ---- sending -----------------------------------------------------------------

    public function test_the_email_goes_out_with_a_working_yes_link_and_honest_footer(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'ask@example.test', 'name' => 'Nur Aina']);

        $this->sender->start($this->campaign());
        $this->sender->dispatch();

        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
            return $mail->hasTo('ask@example.test')
                && str_contains($mail->htmlBody, '/m/s/')
                && ! str_contains($mail->htmlBody, '{{subscribe_url}}')
                && str_contains($mail->htmlBody, 'Hi Nur,')
                // Not "you opted in" — they have not, which is the point.
                && str_contains($mail->htmlBody, 'We will not send marketing emails unless you say yes.')
                && ! str_contains($mail->htmlBody, 'because you opted in');
        });
    }

    public function test_someone_who_opts_in_before_their_turn_is_skipped(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'eager@example.test']);

        $this->sender->start($this->campaign());
        Consent::grant('eager@example.test', 'settings');
        $this->sender->dispatch();

        Mail::assertNotSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('eager@example.test'));
    }

    public function test_a_re_permission_email_without_a_yes_link_cannot_be_sent(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['design' => ['root' => ['props' => []], 'content' => [
            ['type' => 'Text', 'props' => ['html' => '<p>Hello</p>']],
        ]]]);

        $this->expectExceptionMessage('{{subscribe_url}}');

        $this->sender->start($campaign);
    }

    // ---- answering ---------------------------------------------------------------

    private function sentTo(string $email): EmailSend
    {
        Mail::fake();
        User::factory()->create(['email' => $email]);
        $this->sender->start($this->campaign());
        $this->sender->dispatch();

        return EmailSend::where('email', $email)->firstOrFail();
    }

    public function test_opening_the_yes_link_does_not_subscribe(): void
    {
        // Mail scanners open every link. Consent recorded on their visit would
        // be consent nobody gave.
        $send = $this->sentTo('scanned@example.test');

        $this->get("/m/s/{$send->token}")->assertOk()->assertSee('Yes, keep me posted', false);

        $this->assertFalse(Consent::isSubscribed('scanned@example.test'));
    }

    public function test_pressing_yes_subscribes_with_the_source_recorded(): void
    {
        $send = $this->sentTo('agrees@example.test');

        $this->post("/m/s/{$send->token}")->assertOk()->assertSee("You're in", false);

        $this->assertTrue(Consent::isSubscribed('agrees@example.test'));
        $this->assertDatabaseHas('email_consents', ['email' => 'agrees@example.test', 'source' => 'repermission']);
    }

    public function test_a_normal_campaign_token_cannot_be_used_to_subscribe(): void
    {
        // The yes link belongs to the re-permission email only.
        $campaign = EmailCampaign::create(['name' => 'Normal', 'subject' => 'x']);
        $send = EmailSend::create(['campaign_id' => $campaign->id, 'email' => 'someone@example.test', 'token' => str_repeat('k', 40), 'status' => 'sent']);

        $this->post("/m/s/{$send->token}")->assertOk()->assertSee('This link has expired', false);

        $this->assertFalse(Consent::isSubscribed('someone@example.test'));
    }

    public function test_unsubscribing_from_the_question_means_never_being_asked_again(): void
    {
        $send = $this->sentTo('nothanks@example.test');

        $this->post("/m/u/{$send->token}");

        $this->assertFalse(Consent::isSubscribed('nothanks@example.test'));
        $this->assertNotContains('nothanks@example.test', Audience::repermission()->pluck('email')->all());
    }

    // ---- admin -------------------------------------------------------------------

    public function test_the_admin_can_prepare_one_and_sees_who_said_yes(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->post('/admin/edm/campaigns', ['name' => 'Ask', 'kind' => 'repermission']);

        $campaign = EmailCampaign::firstOrFail();
        $this->assertSame('repermission', $campaign->kind);
        $this->assertStringContainsString('{{subscribe_url}}', json_encode($campaign->design));

        $this->actingAs($admin)->get("/admin/edm/campaigns/{$campaign->id}")
            ->assertInertia(fn ($p) => $p->where('confirmed', 0)->where('campaign.kind', 'repermission'));
    }
}
