<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendeeManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Event, 1: Ticket} */
    private function eventWithTicket(string $status = 'valid'): array
    {
        $host = $this->organizer();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Gala', 'slug' => 'gala-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
        $order = Order::create(['reference' => 'DRSVP-'.strtoupper(substr(uniqid(), -6)), 'event_id' => $event->id, 'status' => 'paid', 'total' => 0, 'buyer_name' => 'Buyer', 'buyer_email' => 'b@x.com']);
        $ticket = $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => 'Ali', 'status' => $status]);

        return [$event, $ticket];
    }

    public function test_index_lists_admissions_with_stats(): void
    {
        [$event] = $this->eventWithTicket();

        $this->actingAs($event->user)->get(route('host.events.attendees', $event))
            ->assertInertia(fn (Assert $p) => $p->component('host/events/attendees')
                ->where('stats.total', 1)
                ->where('stats.checked_in', 0)
                ->has('tickets.data', 1)
                ->where('tickets.data.0.name', 'Ali'));
    }

    public function test_scan_with_auto_checks_in_and_returns_stats(): void
    {
        [$event, $ticket] = $this->eventWithTicket();

        $this->actingAs($event->user)
            ->postJson(route('host.events.attendees.scan', $event), ['token' => $ticket->qr_token, 'auto' => true])
            ->assertOk()
            ->assertJson(['result' => 'ok', 'stats' => ['checked_in' => 1]]);

        $this->assertSame('checked_in', $ticket->fresh()->status);
    }

    public function test_scan_without_auto_only_looks_up(): void
    {
        [$event, $ticket] = $this->eventWithTicket();

        $this->actingAs($event->user)
            ->postJson(route('host.events.attendees.scan', $event), ['token' => $ticket->qr_token])
            ->assertOk()
            ->assertJson(['result' => 'valid']);

        $this->assertSame('valid', $ticket->fresh()->status, 'lookup must not check the ticket in');
    }

    public function test_rescan_reports_already_checked_in(): void
    {
        [$event, $ticket] = $this->eventWithTicket('checked_in');

        $this->actingAs($event->user)
            ->postJson(route('host.events.attendees.scan', $event), ['token' => $ticket->qr_token, 'auto' => true])
            ->assertOk()
            ->assertJson(['result' => 'already']);
    }

    public function test_scan_accepts_a_full_pass_url(): void
    {
        [$event, $ticket] = $this->eventWithTicket();

        $this->actingAs($event->user)
            ->postJson(route('host.events.attendees.scan', $event), ['token' => "https://droprsvp.com/tickets/{$ticket->qr_token}", 'auto' => true])
            ->assertOk()->assertJson(['result' => 'ok']);
    }

    public function test_cross_event_token_is_not_found(): void
    {
        [$event] = $this->eventWithTicket();
        [, $other] = $this->eventWithTicket();

        $this->actingAs($event->user)
            ->postJson(route('host.events.attendees.scan', $event), ['token' => $other->qr_token, 'auto' => true])
            ->assertOk()->assertJson(['result' => 'notfound']);

        $this->assertSame('valid', $other->fresh()->status);
    }

    public function test_manual_check_in_and_undo(): void
    {
        [$event, $ticket] = $this->eventWithTicket();

        $this->actingAs($event->user)
            ->postJson(route('host.events.attendees.checkin', [$event, $ticket->id]))
            ->assertOk()->assertJson(['ticket' => ['status' => 'checked_in']]);
        $this->assertSame('checked_in', $ticket->fresh()->status);

        $this->actingAs($event->user)
            ->postJson(route('host.events.attendees.undo', [$event, $ticket->id]))
            ->assertOk()->assertJson(['ticket' => ['status' => 'valid']]);
        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function test_export_streams_csv(): void
    {
        [$event] = $this->eventWithTicket();

        $res = $this->actingAs($event->user)->get(route('host.events.attendees.export', $event));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
    }

    public function test_non_owner_is_forbidden(): void
    {
        [$event, $ticket] = $this->eventWithTicket();
        $intruder = $this->organizer();

        $this->actingAs($intruder)->get(route('host.events.attendees', $event))->assertForbidden();
        $this->actingAs($intruder)->postJson(route('host.events.attendees.scan', $event), ['token' => $ticket->qr_token])->assertForbidden();
    }
}
