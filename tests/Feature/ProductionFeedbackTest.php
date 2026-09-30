<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\Event;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A round of reports from production: the password gate that would not let go,
 * city chips pointing at empty pages, and an undo at the door with nothing
 * standing behind it.
 */
class ProductionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A plain account. Fine for owning an event on the public side, but the
     * /host routes need the organizer role — see hostUser().
     */
    private function host(): User
    {
        return User::factory()->create(['slug' => 'host-'.uniqid()]);
    }

    /** An approved organizer, who can reach the door console. */
    private function hostUser(): User
    {
        return $this->organizer(['slug' => 'host-'.uniqid()]);
    }

    /** Set the admin-curated nearby-city list. */
    private function curate(array $nearby): void
    {
        Setting::putArray('landing_sections', ['nearby_cities' => $nearby]);
    }

    private function eventIn(?string $city, array $overrides = []): Event
    {
        return Event::create(array_merge([
            'user_id' => $this->host()->id,
            'title' => 'Event in '.($city ?? 'nowhere'),
            'slug' => 'ev-'.uniqid(),
            'status' => 'published',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'city' => $city,
            'starts_at' => now()->addWeek(),
        ], $overrides));
    }

    // ---- the password gate --------------------------------------------------

    public function test_resetting_a_forgotten_password_releases_the_set_password_gate(): void
    {
        // Accounts created FOR someone — at checkout, or by an admin — are
        // flagged to choose their own password. That flag was only ever cleared
        // by the set-password screen and by Google sign-in, so anyone who used
        // "forgot password" instead reset it, signed in, and was sent straight
        // back to "Set your password" with no way through.
        $user = User::factory()->create();
        $user->forceFill(['must_set_password' => true])->save();

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'a-Str0ng-New-Passw0rd!',
            'password_confirmation' => 'a-Str0ng-New-Passw0rd!',
        ]);

        $this->assertFalse((bool) $user->fresh()->must_set_password);
    }

    public function test_a_user_who_reset_their_password_is_not_redirected_to_set_one(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['must_set_password' => true])->save();

        // Before: the gate holds.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.set'));

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'a-Str0ng-New-Passw0rd!',
            'password_confirmation' => 'a-Str0ng-New-Passw0rd!',
        ]);

        // After: through to the app.
        $this->actingAs($user->fresh())->get('/dashboard')->assertSuccessful();
    }

    public function test_the_gate_still_holds_for_someone_who_has_not_chosen_a_password(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['must_set_password' => true])->save();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.set'));
    }

    // ---- city chips ---------------------------------------------------------

    public function test_only_cities_with_events_are_offered(): void
    {
        $this->curate([
            'enabled' => true,
            'heading' => 'Popular near you',
            'cities' => ['Kuala Lumpur', 'Ipoh', 'George Town'],
        ]);

        $this->eventIn('Kajang');
        $this->eventIn('Kuala Lumpur');

        $this->get('/en-my/')->assertOk()->assertInertia(fn (Assert $p) => $p
            // Two chips, not four: Ipoh and George Town were curated but have
            // no events, and every one of those chips led to an empty page.
            ->has('sections.nearby_cities.cities', 2)
            // Curated and has events, so it keeps its place at the front.
            ->where('sections.nearby_cities.cities.0.name', 'Kuala Lumpur')
            // Has events but was never curated — still worth showing.
            ->where('sections.nearby_cities.cities.1.name', 'Kajang'));
    }

    public function test_the_curated_order_is_respected_for_cities_that_qualify(): void
    {
        $this->curate([
            'enabled' => true, 'heading' => 'Popular near you',
            'cities' => ['Shah Alam', 'Kuala Lumpur'],
        ]);

        $this->eventIn('Kuala Lumpur');
        $this->eventIn('Shah Alam');

        $this->get('/en-my/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('sections.nearby_cities.cities.0.name', 'Shah Alam')
            ->where('sections.nearby_cities.cities.1.name', 'Kuala Lumpur'));
    }

    public function test_a_past_event_does_not_keep_a_city_on_the_list(): void
    {
        $this->curate([
            'enabled' => true, 'heading' => 'Popular near you', 'cities' => ['Ipoh'],
        ]);

        $this->eventIn('Ipoh', ['starts_at' => now()->subMonth()]);

        $this->get('/en-my/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('sections.nearby_cities.cities', 0));
    }

    public function test_a_draft_event_does_not_put_its_city_on_the_list(): void
    {
        $this->curate([
            'enabled' => true, 'heading' => 'Popular near you', 'cities' => [],
        ]);

        $this->eventIn('Melaka', ['status' => 'draft']);

        $this->get('/en-my/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('sections.nearby_cities.cities', 0));
    }

    public function test_each_chip_carries_its_event_count(): void
    {
        $this->curate([
            'enabled' => true, 'heading' => 'Popular near you', 'cities' => ['Kajang'],
        ]);

        $this->eventIn('Kajang');
        $this->eventIn('Kajang');

        $this->get('/en-my/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('sections.nearby_cities.cities.0.name', 'Kajang')
            ->where('sections.nearby_cities.cities.0.events', 2));
    }

    // ---- check-in undo ------------------------------------------------------

    private function ticketFor(Event $event): Ticket
    {
        $type = TicketType::create([
            'event_id' => $event->id, 'name' => 'GA', 'kind' => 'paid',
            'price' => 10, 'currency' => 'MYR', 'quantity' => 10, 'is_active' => true,
        ]);
        $order = Order::create([
            'reference' => 'R-'.uniqid(), 'event_id' => $event->id, 'status' => 'paid',
            'total' => 10, 'currency' => 'MYR', 'buyer_name' => 'Aisyah', 'paid_at' => now(),
        ]);

        return Ticket::create([
            'order_id' => $order->id, 'ticket_type_id' => $type->id, 'event_id' => $event->id,
            'qr_token' => 'tok-'.uniqid(), 'attendee_name' => 'Aisyah', 'status' => 'valid',
        ]);
    }

    public function test_undoing_a_check_in_keeps_a_record_of_it(): void
    {
        $host = $this->hostUser();
        $event = $this->eventIn('Kajang', ['user_id' => $host->id]);
        $ticket = $this->ticketFor($event);

        $this->actingAs($host)->postJson("/host/events/{$event->slug}/attendees/{$ticket->id}/check-in")->assertOk();
        $this->actingAs($host)->postJson("/host/events/{$event->slug}/attendees/{$ticket->id}/undo")->assertOk();

        $log = $ticket->fresh()->check_in_log;

        // Undo used to null out checked_in_at and checked_in_by, leaving nothing
        // to say the ticket had ever been scanned, by whom, or who reversed it.
        $this->assertCount(2, $log);
        $this->assertSame('in', $log[0]['action']);
        $this->assertSame('undo', $log[1]['action']);
        $this->assertSame($host->id, $log[1]['by']);
    }

    public function test_undoing_a_ticket_that_is_not_checked_in_is_refused(): void
    {
        $host = $this->hostUser();
        $event = $this->eventIn('Kajang', ['user_id' => $host->id]);
        $ticket = $this->ticketFor($event);

        // Not a silent no-op: at a door, a tap that appears to work but does
        // nothing is worse than one that says why.
        $this->actingAs($host)
            ->postJson("/host/events/{$event->slug}/attendees/{$ticket->id}/undo")
            ->assertStatus(422)
            ->assertJsonPath('result', 'invalid');
    }

    public function test_a_refunded_ticket_is_not_reinstated_by_an_undo(): void
    {
        $host = $this->hostUser();
        $event = $this->eventIn('Kajang', ['user_id' => $host->id]);
        $ticket = $this->ticketFor($event);
        $ticket->forceFill(['status' => 'refunded'])->save();

        $this->actingAs($host)
            ->postJson("/host/events/{$event->slug}/attendees/{$ticket->id}/undo")
            ->assertStatus(422);

        $this->assertSame('refunded', $ticket->fresh()->status);
    }

    public function test_the_check_in_log_cannot_grow_without_bound(): void
    {
        $host = $this->hostUser();
        $event = $this->eventIn('Kajang', ['user_id' => $host->id]);
        $ticket = $this->ticketFor($event);

        for ($i = 0; $i < 15; $i++) {
            $this->actingAs($host)->postJson("/host/events/{$event->slug}/attendees/{$ticket->id}/check-in");
            $this->actingAs($host)->postJson("/host/events/{$event->slug}/attendees/{$ticket->id}/undo");
        }

        $log = $ticket->fresh()->check_in_log;

        $this->assertLessThanOrEqual(20, count($log));
        // The most recent entries survive the trim.
        $this->assertSame('undo', $log[count($log) - 1]['action']);
    }

    public function test_a_second_check_in_of_the_same_ticket_does_not_double_log(): void
    {
        $host = $this->hostUser();
        $event = $this->eventIn('Kajang', ['user_id' => $host->id]);
        $ticket = $this->ticketFor($event);

        $this->actingAs($host)->postJson("/host/events/{$event->slug}/attendees/{$ticket->id}/check-in")->assertOk();
        $this->actingAs($host)->postJson("/host/events/{$event->slug}/attendees/{$ticket->id}/check-in")->assertOk();

        $this->assertCount(1, $ticket->fresh()->check_in_log);
    }

    // ---- state / city -------------------------------------------------------

    public function test_the_about_you_page_offers_cities_to_pick_from(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)->get('/profile/about-you')->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('cities')
                // Each carries its state, so the picker can narrow the list.
                ->has('cities.0.state')
                ->has('cities.0.name'));
    }

    // ---- finance filter options --------------------------------------------

    public function test_finance_filter_options_carry_a_hint_to_tell_them_apart(): void
    {
        $superadmin = User::factory()->create();
        Role::findOrCreate('superadmin', 'web');
        $superadmin->assignRole('superadmin');

        $event = $this->eventIn('Kajang');
        Order::create([
            'reference' => 'R-HINT', 'event_id' => $event->id, 'status' => 'paid',
            'total' => 10, 'fees' => 3, 'currency' => 'MYR', 'paid_at' => now(),
        ]);

        $this->actingAs($superadmin)->get('/admin/finance')->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('options.events.0.hint')
                ->where('options.eventsTruncated', false));
    }
}
