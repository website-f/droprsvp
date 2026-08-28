<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Event, 1: Ticket} */
    private function eventWithTicket(): array
    {
        $host = $this->organizer();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Door Event', 'slug' => 'door-event',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDay(),
        ]);
        $order = Order::create(['reference' => 'DRSVP-DOOR01', 'event_id' => $event->id, 'status' => 'paid', 'total' => 0]);
        $ticket = $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => 'Ali', 'status' => 'valid']);

        return [$event, $ticket];
    }

    public function test_owner_can_check_a_guest_in(): void
    {
        [$event, $ticket] = $this->eventWithTicket();

        $this->actingAs($event->user)
            ->post(route('host.events.checkin.scan', $event), ['token' => $ticket->qr_token])
            ->assertRedirect(route('host.events.checkin', $event))
            ->assertSessionHas('scan', fn ($s) => $s['ok'] === true);

        $this->assertSame('checked_in', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->checked_in_at);
    }

    public function test_second_scan_reports_already_checked_in(): void
    {
        [$event, $ticket] = $this->eventWithTicket();
        $ticket->update(['status' => 'checked_in', 'checked_in_at' => now()]);

        $this->actingAs($event->user)
            ->post(route('host.events.checkin.scan', $event), ['token' => $ticket->qr_token])
            ->assertSessionHas('scan', fn ($s) => $s['ok'] === false && ($s['already'] ?? false) === true);
    }

    public function test_token_from_another_event_is_rejected(): void
    {
        [$event] = $this->eventWithTicket();
        [, $otherTicket] = $this->makeOtherEventTicket();

        $this->actingAs($event->user)
            ->post(route('host.events.checkin.scan', $event), ['token' => $otherTicket->qr_token])
            ->assertSessionHas('scan', fn ($s) => $s['ok'] === false);

        $this->assertSame('valid', $otherTicket->fresh()->status, 'the other event ticket is untouched');
    }

    public function test_non_owner_cannot_check_in(): void
    {
        [$event, $ticket] = $this->eventWithTicket();
        $intruder = $this->organizer();

        $this->actingAs($intruder)
            ->post(route('host.events.checkin.scan', $event), ['token' => $ticket->qr_token])
            ->assertForbidden();
    }

    /** @return array{0: Event, 1: Ticket} */
    private function makeOtherEventTicket(): array
    {
        $host = $this->organizer();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Other', 'slug' => 'other-event',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
        $order = Order::create(['reference' => 'DRSVP-OTHER1', 'event_id' => $event->id, 'status' => 'paid', 'total' => 0]);
        $ticket = $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => 'Abu', 'status' => 'valid']);

        return [$event, $ticket];
    }
}
