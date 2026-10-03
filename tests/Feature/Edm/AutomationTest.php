<?php

namespace Tests\Feature\Edm;

use App\Mail\CampaignMail;
use App\Models\EdmAutomation;
use App\Models\EdmEnrollment;
use App\Models\EmailCampaign;
use App\Models\EmailSend;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\OrganizerProfile;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Edm\Automations;
use App\Services\Edm\CampaignSender;
use App\Services\Edm\Credits;
use App\Support\Edm\Consent;
use App\Support\Edm\OrganizerRules;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 3: sequences that send themselves. */
class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private Event $event;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Organizer email is off until an admin switches it on; these tests are about using it.
        OrganizerRules::saveGlobal(['enabled' => true]);
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
        config(['edm.hourly_limit' => 6000, 'edm.warmup.enabled' => false]);

        $this->org = $this->organizer(['name' => 'Sophia Kuek', 'slug' => 'boardlah']);
        OrganizerProfile::create(['user_id' => $this->org->id, 'business_name' => 'BoardLah Entertainment', 'status' => 'approved']);
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);
        $this->event = Event::create([
            'user_id' => $this->org->id, 'category_id' => $category->id, 'title' => 'Clocktower Night', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'venue_name' => 'HOL Cafe', 'city' => 'Kajang',
            // 2.5 days away: the "3 days before" reminder is already too late.
            'starts_at' => now()->addHours(60), 'ends_at' => now()->addHours(63), 'published_at' => now(),
        ]);

        Role::findOrCreate('superadmin', 'web');
        $this->admin = User::factory()->create(['name' => 'Aisyah Admin']);
        $this->admin->assignRole('superadmin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidOrder(string $email = 'fan@example.com', string $name = 'Ali Hassan'): Order
    {
        return Order::create(['reference' => 'R'.uniqid(), 'event_id' => $this->event->id, 'status' => 'paid', 'buyer_email' => $email, 'buyer_name' => $name, 'paid_at' => now(), 'total' => 20]);
    }

    private function run_(): array
    {
        return Automations::run();
    }

    // ---- recipes ---------------------------------------------------------------

    public function test_a_recipe_creates_written_steps_hidden_from_campaigns(): void
    {
        $a = Automations::create('event_reminder', null, $this->admin->id);

        $this->assertSame(['3 days before', '1 day before'], $a->steps->map->timingLabel()->all());
        $this->assertSame('automation', $a->steps[0]->campaign->kind);
        $this->assertStringContainsString('{{event_name}}', $a->steps[0]->campaign->subject);

        $this->actingAs($this->admin)->get('/admin/edm/campaigns')->assertOk()
            ->assertInertia(fn ($p) => $p->where('campaigns.data', []));
        $this->actingAs($this->admin)->get('/admin/edm/campaigns/'.$a->steps[0]->campaign_id)->assertNotFound();
    }

    // ---- reminders ---------------------------------------------------------------

    public function test_reminders_skip_late_steps_and_fill_in_the_event(): void
    {
        $this->paidOrder();
        $a = Automations::create('event_reminder', null, $this->admin->id);
        Automations::activate($a);

        $this->assertSame(1, $this->run_()['enrolled']);
        $e = EdmEnrollment::firstOrFail();
        $this->assertSame(1, $e->next_step);                                   // skipped "3 days before"
        $this->assertTrue($e->next_at->equalTo($this->event->starts_at->copy()->subDay()));
        $this->assertSame(0, EmailSend::count());

        // Running again enrols nobody twice.
        $this->assertSame(0, $this->run_()['enrolled']);

        Carbon::setTestNow(now()->addHours(37));                               // past "1 day before"
        $this->assertSame(1, $this->run_()['queued']);
        $send = EmailSend::firstOrFail();
        $this->assertSame('Clocktower Night', $send->context['event_name']);
        $this->assertSame('HOL Cafe, Kajang', $send->context['event_venue']);

        app(CampaignSender::class)->dispatch();
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->subjectLine === 'Tomorrow: Clocktower Night' && str_contains($m->htmlBody, 'HOL Cafe, Kajang'));
        $this->assertSame('completed', $e->fresh()->status);
        $this->assertSame(1, $a->steps[1]->campaign->fresh()->sent_count); // per-step statistics
    }

    public function test_reminders_respect_the_account_preference_and_refunds(): void
    {
        $quiet = User::factory()->create(['email' => 'quiet@example.com', 'notification_preferences' => ['event_reminders' => false]]);
        Order::create(['reference' => 'RQ', 'event_id' => $this->event->id, 'status' => 'paid', 'buyer_email' => 'quiet@example.com', 'user_id' => $quiet->id, 'paid_at' => now(), 'total' => 20]);
        $refunded = $this->paidOrder('later@example.com');

        $a = Automations::create('event_reminder', null, $this->admin->id);
        Automations::activate($a);
        $this->run_();
        $this->assertSame(['later@example.com'], EdmEnrollment::pluck('email')->all());

        $refunded->update(['status' => 'refunded']);
        Carbon::setTestNow(now()->addHours(37));
        $r = $this->run_();

        $this->assertSame(1, $r['exited']);
        $this->assertSame('Order refunded or cancelled', EdmEnrollment::first()->exit_reason);
    }

    // ---- abandoned checkout -----------------------------------------------------------

    public function test_abandoned_checkout_recovers_then_stops_when_they_buy(): void
    {
        $a = Automations::create('abandoned_checkout', null, $this->admin->id);
        Automations::activate($a);
        // Only checkouts abandoned after it was switched on count.
        Carbon::setTestNow(now()->addHours(2));

        $order = Order::create(['reference' => 'AB1', 'event_id' => $this->event->id, 'status' => 'cancelled', 'buyer_email' => 'Left@Example.com', 'buyer_name' => 'Lee', 'total' => 20]);
        $order->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $this->assertSame(0, $this->run_()['queued']);           // 1 hour after: not yet
        Carbon::setTestNow(now()->addMinutes(20));
        $r = $this->run_();
        $this->assertSame(1, $r['queued']);
        $this->assertSame('left@example.com', EmailSend::first()->email);

        // They come back and buy: the "1 day after" never goes.
        $this->paidOrder('left@example.com');
        Carbon::setTestNow(now()->addDay());
        $this->assertSame(1, $this->run_()['exited']);
        $this->assertSame('Bought tickets', EdmEnrollment::first()->exit_reason);
    }

    // ---- conditions & consent ---------------------------------------------------------

    public function test_post_event_thanks_only_those_who_checked_in_and_promotes_only_to_subscribers(): void
    {
        $this->event->update(['starts_at' => now()->subHours(6), 'ends_at' => now()->subHours(2)]);
        $came = $this->paidOrder('came@example.com');
        $this->paidOrder('noshow@example.com');
        $type = TicketType::create(['event_id' => $this->event->id, 'name' => 'GA', 'kind' => 'paid', 'price' => 20]);
        Ticket::create(['order_id' => $came->id, 'event_id' => $this->event->id, 'ticket_type_id' => $type->id, 'attendee_name' => 'C', 'status' => 'checked_in', 'checked_in_at' => now()->subHours(5), 'qr_token' => 'q1']);
        Consent::grant('came@example.com', 'checkout', null, $this->org->id); // on the organizer's list

        $a = Automations::create('post_event', $this->org->id, $this->org->id);
        Credits::grant($this->org->id, 100);
        Automations::activate($a);
        $this->run_();

        Carbon::setTestNow(now()->addDay());
        $r = $this->run_();
        $this->assertSame(1, $r['queued']);   // thank-you: checked in
        $this->assertSame(1, $r['skipped']);  // no-show
        $this->assertSame(1, $a->steps[0]->fresh()->skipped_count);

        Carbon::setTestNow(now()->addDays(6));
        $r = $this->run_();
        $this->assertSame(1, $r['queued']);   // "what's next": subscriber only
        $this->assertSame(1, $r['skipped']);
        $this->assertSame(98, Credits::balance($this->org->id)); // organizers pay per automated email
    }

    public function test_unsubscribing_from_an_automated_email_stops_the_rest(): void
    {
        $this->event->update(['starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHours(3)]);
        $this->paidOrder();
        $a = Automations::create('event_reminder', null, $this->admin->id);
        Automations::activate($a);
        $this->run_();
        Carbon::setTestNow(now()->addDay()->addHour());
        $this->run_();
        $send = EmailSend::firstOrFail();

        $this->post("/m/u/{$send->token}")->assertOk();

        $this->assertTrue(Consent::optedOutOfAutomations('fan@example.com'));
        $this->assertSame('exited', EdmEnrollment::first()->status);
    }

    public function test_an_organizers_own_sequence_replaces_the_platforms_for_their_events(): void
    {
        $this->paidOrder();
        $platform = Automations::create('event_reminder', null, $this->admin->id);
        Automations::activate($platform);
        $theirs = Automations::create('event_reminder', $this->org->id, $this->org->id);
        Automations::activate($theirs);

        $this->run_();

        $this->assertSame([$theirs->id], EdmEnrollment::pluck('automation_id')->all());
    }

    public function test_an_organizer_without_credits_sends_nothing(): void
    {
        // "1 day before" fell due an hour ago: within the grace period, so due now.
        $this->event->update(['starts_at' => now()->addHours(23)]);
        $this->paidOrder();
        $a = Automations::create('event_reminder', $this->org->id, $this->org->id);
        Automations::activate($a);

        $r = $this->run_();

        $this->assertSame(0, $r['queued']);
        $this->assertSame(1, $r['skipped']);
    }

    public function test_welcome_greets_new_subscribers_only(): void
    {
        Consent::grant('before@example.com', 'register');
        Carbon::setTestNow(now()->addMinute());
        $a = Automations::create('welcome', null, $this->admin->id);
        Automations::activate($a);
        Carbon::setTestNow(now()->addMinute());
        Consent::grant('new@example.com', 'checkout');

        $r = $this->run_();

        $this->assertSame(1, $r['enrolled']);
        $this->assertSame(1, $r['queued']);
        $this->assertSame('new@example.com', EmailSend::first()->email);
    }

    // ---- the builder ---------------------------------------------------------------

    public function test_the_builder_pages_and_step_editing(): void
    {
        $this->actingAs($this->admin)->get('/admin/edm/automations')->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/edm/automations/index')->has('triggers', 4));

        $this->actingAs($this->admin)->post('/admin/edm/automations', ['trigger' => 'abandoned_checkout'])->assertRedirect();
        $a = EdmAutomation::firstOrFail();

        $this->actingAs($this->admin)->get("/admin/edm/automations/{$a->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/edm/automations/show')->has('steps', 2)->where('automation.anchor', 'they left checkout'));

        // Retime the second email to 30 minutes: it moves first.
        $second = $a->steps[1];
        $this->actingAs($this->admin)->put("/admin/edm/automations/{$a->id}/steps/{$second->id}", [
            'delay_value' => 30, 'delay_unit' => 'minutes', 'delay_direction' => 'after', 'conditions' => ['not_purchased'], 'subject' => 'Quick one', 'marketing' => false,
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $second->fresh()->position);

        // Only reminders may send "before".
        $this->actingAs($this->admin)->put("/admin/edm/automations/{$a->id}/steps/{$second->id}", [
            'delay_value' => 1, 'delay_unit' => 'days', 'delay_direction' => 'before', 'subject' => 'X',
        ])->assertSessionHasErrors('delay_direction');

        $this->actingAs($this->admin)->post("/admin/edm/automations/{$a->id}/steps")->assertRedirect();
        $this->assertSame(3, $a->steps()->count());

        $this->actingAs($this->admin)->get("/admin/edm/automations/{$a->id}/steps/{$second->id}/preview")->assertOk()->assertSee('Rooftop Jazz Night', false);
        $this->actingAs($this->admin)->post("/admin/edm/automations/{$a->id}/activate")->assertSessionHas('flash_success');
        $this->assertTrue($a->fresh()->isActive());
        $this->assertSame('sending', $second->campaign->fresh()->status);

        $this->actingAs($this->admin)->post("/admin/edm/automations/{$a->id}/pause")->assertRedirect();
        $this->assertSame('paused', $second->campaign->fresh()->status);
    }

    public function test_organizer_automations_are_their_own(): void
    {
        $this->actingAs($this->org)->post('/host/edm/automations', ['trigger' => 'event_reminder'])->assertRedirect();
        $a = EdmAutomation::firstOrFail();
        $this->assertSame($this->org->id, (int) $a->organizer_id);

        $this->actingAs($this->org)->get("/host/edm/automations/{$a->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('host/edm/automations/show')->where('base', '/host/edm'));
        $this->actingAs($this->admin)->get("/admin/edm/automations/{$a->id}")->assertNotFound();

        $other = $this->organizer();
        OrganizerProfile::create(['user_id' => $other->id, 'business_name' => 'Other', 'status' => 'approved']);
        $this->actingAs($other)->get("/host/edm/automations/{$a->id}")->assertNotFound();
    }

    public function test_the_command_runs(): void
    {
        $this->artisan('edm:automations')->assertSuccessful();
    }

    public function test_a_campaign_cannot_reuse_the_dedupe_slot_twice(): void
    {
        $c = EmailCampaign::create(['name' => 'X', 'subject' => 'Y']);
        EmailSend::create(['campaign_id' => $c->id, 'email' => 'a@example.com', 'token' => str_repeat('a', 40)]);

        $this->expectException(QueryException::class);
        EmailSend::create(['campaign_id' => $c->id, 'email' => 'a@example.com', 'token' => str_repeat('b', 40)]);
    }
}
