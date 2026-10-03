<?php

namespace Tests\Feature\Edm;

use App\Models\EdmAutomation;
use App\Models\EmailCampaign;
use App\Models\EmailSend;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrganizerProfile;
use App\Models\User;
use App\Services\Edm\CampaignSender;
use App\Support\Edm\Audience;
use App\Support\Edm\Consent;
use App\Support\Edm\OrganizerRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Organizer email under the platform's rules: only people who joined their
 * events, access switched on and off globally and per organizer, and limits
 * on volume, size and frequency.
 */
class OrganizerRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['edm.hourly_limit' => 6000, 'edm.warmup.enabled' => false, 'edm.organizers.free_allowance' => 100000]);

        $this->org = $this->organizer(['name' => 'Host']);
        OrganizerProfile::create(['user_id' => $this->org->id, 'business_name' => 'BoardLah', 'status' => 'approved']);
        $this->event = Event::create(['user_id' => $this->org->id, 'title' => 'Gig', 'slug' => 'gig', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
    }

    private function fans(int $n, ?User $org = null, ?Event $event = null, string $prefix = 'fan'): void
    {
        $org ??= $this->org;
        $event ??= $this->event;

        for ($i = 0; $i < $n; $i++) {
            $email = "{$prefix}{$i}@example.com";
            Consent::grant($email, 'checkout', null, $org->id);
            Order::create(['reference' => 'R-'.substr(md5($email.$event->id), 0, 10), 'event_id' => $event->id, 'buyer_email' => $email, 'status' => 'paid', 'paid_at' => now(), 'total' => 0, 'currency' => 'MYR']);
        }
    }

    private function campaign(?User $org = null): EmailCampaign
    {
        return EmailCampaign::create([
            'organizer_id' => ($org ?? $this->org)->id, 'name' => 'News', 'subject' => 'Hello',
            'design' => ['root' => ['props' => []], 'content' => [['type' => 'Text', 'props' => ['html' => '<p>Our next night is on Saturday — see you there.</p>']]]],
            'audience' => [],
        ]);
    }

    private function rules(array $values): void
    {
        OrganizerRules::saveGlobal($values);
    }

    // ---- who can be reached -------------------------------------------------------

    public function test_only_people_who_joined_an_event_are_reachable(): void
    {
        $this->fans(2);
        // Ticked "email me" at checkout, then never paid.
        Consent::grant('abandoned@example.com', 'checkout', null, $this->org->id);
        Order::create(['reference' => 'R-ABANDON', 'event_id' => $this->event->id, 'buyer_email' => 'abandoned@example.com', 'status' => 'pending', 'total' => 10, 'currency' => 'MYR']);

        $this->assertSame(2, Audience::count([], $this->org->id));

        $c = app(CampaignSender::class)->start($this->campaign());
        $this->assertSame(2, $c->recipients_count);
        $this->assertFalse(EmailSend::where('email', 'abandoned@example.com')->exists());

        // The subscriber list says who is reachable, and why not.
        $this->actingAs($this->org)->get('/host/edm/subscribers')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('counts.subscribed', 3)->where('counts.reachable', 2));
    }

    // ---- access ---------------------------------------------------------------------

    public function test_the_master_switch_turns_it_off_for_everyone(): void
    {
        $this->fans(1);
        $this->rules(['enabled' => false]);

        $this->actingAs($this->org)->get('/host/edm')->assertForbidden()
            ->assertInertia(fn (Assert $p) => $p->component('host/edm/locked')->where('upgrade', false));
        $this->expectException(RuntimeException::class);
        app(CampaignSender::class)->start($this->campaign());
    }

    public function test_switching_off_mid_send_pauses_the_campaign(): void
    {
        $this->fans(3);
        $c = app(CampaignSender::class)->start($this->campaign());
        $this->rules(['enabled' => false]);

        $this->assertSame(0, app(CampaignSender::class)->dispatch());
        $this->assertSame('paused', $c->fresh()->status);
        $this->assertStringContainsString('switched off', $c->fresh()->paused_reason);
    }

    public function test_premium_only_access_and_per_organizer_overrides(): void
    {
        $this->rules(['access' => 'premium']);

        $this->actingAs($this->org)->get('/host/edm')->assertForbidden()
            ->assertInertia(fn (Assert $p) => $p->where('upgrade', true));
        $this->actingAs($this->org)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->where('auth.organizer_edm.state', 'locked'));

        // Switched on for this one organizer, Premium or not.
        OrganizerRules::saveFor($this->org->id, 'enabled', []);
        $this->actingAs($this->org)->get('/host/edm')->assertOk();

        // And switched off for one organizer while everyone else has it.
        $this->rules(['access' => 'all']);
        OrganizerRules::saveFor($this->org->id, 'disabled', []);
        $this->actingAs($this->org)->get('/host/edm')->assertForbidden();
        $this->actingAs($this->org)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->where('auth.organizer_edm.state', 'off'));
    }

    public function test_invitation_mode_lets_in_only_organizers_switched_on(): void
    {
        $this->rules(['access' => 'selected']);
        $this->actingAs($this->org)->get('/host/edm')->assertForbidden();

        OrganizerRules::saveFor($this->org->id, 'enabled', []);
        $this->actingAs($this->org)->get('/host/edm')->assertOk();
    }

    // ---- size and frequency -------------------------------------------------------------

    public function test_recipients_per_campaign_is_capped_and_the_start_rolls_back(): void
    {
        $this->fans(5);
        $this->rules(['max_recipients' => 3]);
        $c = $this->campaign();

        try {
            app(CampaignSender::class)->start($c);
            $this->fail('Expected the size limit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('at most 3', $e->getMessage());
        }

        $this->assertSame('draft', $c->fresh()->status);
        $this->assertSame(0, EmailSend::count());

        // A per-organizer override beats the global rule.
        OrganizerRules::saveFor($this->org->id, 'inherit', ['max_recipients' => 10]);
        $this->assertSame(5, app(CampaignSender::class)->start($c->fresh())->recipients_count);
    }

    public function test_campaigns_per_day_limits_how_often_they_send(): void
    {
        $this->fans(1);
        $this->rules(['campaigns_per_day' => 1, 'per_person_per_week' => 0]);

        app(CampaignSender::class)->start($this->campaign());

        $this->expectExceptionMessage('1 campaign a day');
        app(CampaignSender::class)->start($this->campaign());
    }

    public function test_hourly_limit_paces_one_organizer_without_holding_up_others(): void
    {
        $this->fans(5);
        $other = $this->organizer();
        $otherEvent = Event::create(['user_id' => $other->id, 'title' => 'Other', 'slug' => 'other', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        $this->fans(2, $other, $otherEvent, 'pal');

        OrganizerRules::saveFor($this->org->id, 'inherit', ['hourly_limit' => 2]);
        $mine = app(CampaignSender::class)->start($this->campaign());
        $theirs = app(CampaignSender::class)->start($this->campaign($other));

        app(CampaignSender::class)->dispatch();

        $this->assertSame(2, $mine->fresh()->sent_count);        // paced
        $this->assertSame('sending', $mine->fresh()->status);    // continues next hour
        $this->assertSame(2, $theirs->fresh()->sent_count);      // not held up
        $this->assertSame(['used' => 2, 'limit' => 2], OrganizerRules::usage($this->org->id)['hour']);

        // Two an hour: an hour on, two more; another hour, the last one.
        $this->travel(61)->minutes();
        app(CampaignSender::class)->dispatch();
        $this->assertSame(4, $mine->fresh()->sent_count);
        $this->travel(61)->minutes();
        app(CampaignSender::class)->dispatch();
        $this->assertSame(5, $mine->fresh()->sent_count);
        $this->assertSame('sent', $mine->fresh()->status);
    }

    public function test_one_person_hears_from_one_organizer_at_most_n_times_a_week(): void
    {
        $this->fans(1);
        $this->rules(['per_person_per_week' => 1, 'campaigns_per_day' => 0]);

        app(CampaignSender::class)->start($this->campaign());
        app(CampaignSender::class)->dispatch();
        $second = app(CampaignSender::class)->start($this->campaign());
        app(CampaignSender::class)->dispatch();

        $this->assertSame(0, $second->fresh()->sent_count);
        $this->assertSame('skipped', EmailSend::where('campaign_id', $second->id)->value('status'));
    }

    // ---- features ----------------------------------------------------------------------

    public function test_abandoned_checkout_reminders_are_off_for_organizers_by_default(): void
    {
        $a = EdmAutomation::create(['organizer_id' => $this->org->id, 'trigger' => 'abandoned_checkout', 'name' => 'Come back', 'status' => 'draft']);
        $a->steps()->create(['position' => 1, 'delay_value' => 1, 'delay_unit' => 'hours', 'campaign_id' => $this->campaign()->id]);

        $this->actingAs($this->org)->post("/host/edm/automations/{$a->id}/activate")
            ->assertSessionHas('flash_error');
        $this->assertNotSame('active', $a->fresh()->status);

        // Automations as a whole can be switched off, which locks their pages.
        OrganizerRules::saveFor($this->org->id, 'inherit', ['automations' => false]);
        $this->actingAs($this->org)->get('/host/edm/automations')->assertForbidden();
    }

    // ---- admin ---------------------------------------------------------------------------

    public function test_admin_sets_global_and_per_organizer_rules(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get('/admin/edm/organizer-rules')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/edm/organizer-rules')->where('rules.hourly_limit', 200));

        $this->actingAs($admin)->post('/admin/edm/organizer-rules', [
            'enabled' => true, 'access' => 'premium',
            'hourly_limit' => 50, 'daily_limit' => 500, 'weekly_limit' => 2000, 'max_recipients' => 1000,
            'campaigns_per_day' => 1, 'campaigns_per_week' => 2, 'per_person_per_week' => 1,
            'automations' => true, 'abandoned_checkout' => false, 'domains' => false,
            'premium_allowance' => 3000, 'free_allowance' => 0,
            'packs' => [['key' => 'starter', 'name' => 'Starter', 'credits' => 1000, 'price' => 19.9]],
            'guard' => ['min_sent' => 100, 'bounce_rate' => 4, 'unsubscribe_rate' => 2, 'complaint_rate' => 0.3],
        ])->assertSessionHasNoErrors();

        $g = OrganizerRules::global();
        $this->assertSame([50, 'premium', 3000, 19.9, 0.04], [$g['hourly_limit'], $g['access'], $g['premium_allowance'], $g['packs'][0]['price'], $g['guard']['bounce_rate']]);

        $this->actingAs($admin)->post("/admin/edm/organizers/{$this->org->id}/rules", [
            'access' => 'enabled', 'overrides' => ['hourly_limit' => 500, 'daily_limit' => null, 'domains' => true],
        ])->assertSessionHasNoErrors();

        $mine = OrganizerRules::for($this->org->id);
        $this->assertSame([500, 500, true], [$mine['hourly_limit'], $mine['daily_limit'], $mine['domains']]);
        $this->assertTrue(OrganizerRules::allowed($this->org));

        // Searching finds organizers who have never used EDM, with their usage.
        $this->actingAs($admin)->get('/admin/edm/organizers?q=BoardLah')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('organizers.0.id', $this->org->id)
                ->where('organizers.0.access', 'enabled')
                ->where('organizers.0.usage.hour.limit', 500));
    }
}
