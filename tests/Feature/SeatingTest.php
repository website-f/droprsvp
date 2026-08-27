<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeatingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Event, 1: Ticket} */
    private function eventWithTicket(): array
    {
        $host = User::factory()->create();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Gala', 'slug' => 'gala',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
        $order = Order::create(['reference' => 'DRSVP-SEAT01', 'event_id' => $event->id, 'status' => 'paid', 'total' => 0]);
        $ticket = $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => 'Ali', 'status' => 'valid']);

        return [$event, $ticket];
    }

    public function test_host_can_create_tables_and_assign_a_ticket(): void
    {
        [$event, $ticket] = $this->eventWithTicket();

        $this->actingAs($event->user)->post(route('host.events.seating.tables', $event), [
            'tables' => [['name' => 'Table 1', 'capacity' => 2]],
        ])->assertRedirect();

        $table = $event->seatingTables()->first();
        $this->assertSame('Table 1', $table->name);

        $this->actingAs($event->user)->post(route('host.events.seating.assign', $event), [
            'ticket_id' => $ticket->id, 'seating_table_id' => $table->id,
        ])->assertRedirect();

        $this->assertSame($table->id, $ticket->fresh()->seating_table_id);
    }

    public function test_cannot_assign_beyond_table_capacity(): void
    {
        [$event, $ticket] = $this->eventWithTicket();
        $table = $event->seatingTables()->create(['name' => 'Tiny', 'capacity' => 1]);
        // fill the single seat with another ticket (via its own order)
        $filler = Order::create(['reference' => 'DRSVP-FILL01', 'event_id' => $event->id, 'status' => 'paid', 'total' => 0]);
        $filler->tickets()->create(['event_id' => $event->id, 'seating_table_id' => $table->id, 'attendee_name' => 'X', 'status' => 'valid']);

        $this->actingAs($event->user)->post(route('host.events.seating.assign', $event), [
            'ticket_id' => $ticket->id, 'seating_table_id' => $table->id,
        ])->assertSessionHasErrors('seating');

        $this->assertNull($ticket->fresh()->seating_table_id);
    }

    public function test_deleting_a_table_unassigns_its_tickets(): void
    {
        [$event, $ticket] = $this->eventWithTicket();
        $table = $event->seatingTables()->create(['name' => 'Table 1', 'capacity' => 8]);
        $ticket->update(['seating_table_id' => $table->id]);

        // save with an empty tables set → the table is pruned
        $this->actingAs($event->user)->post(route('host.events.seating.tables', $event), ['tables' => []]);

        $this->assertSame(0, $event->seatingTables()->count());
        $this->assertNull($ticket->fresh()->seating_table_id);
    }

    public function test_non_owner_cannot_manage_seating(): void
    {
        [$event] = $this->eventWithTicket();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('host.events.seating', $event))->assertForbidden();
    }
}
