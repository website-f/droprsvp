<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Setting;
use App\Services\TableAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Gala Dinner',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'ticketing_mode' => 'tables',
            'auto_assign_tables' => true,
            'tables' => [
                ['name' => 'Table 1', 'shape' => 'round', 'capacity' => 8, 'pos_x' => 20, 'pos_y' => 20],
                ['name' => 'Table 2', 'shape' => 'rect', 'capacity' => 6, 'pos_x' => 220, 'pos_y' => 20],
            ],
            'ticketTypes' => [
                ['name' => 'Seat', 'kind' => 'paid', 'price' => '50', 'min_per_order' => 1, 'max_per_order' => 10, 'is_active' => true],
            ],
            'sessions' => [], 'gallery' => [], 'sections' => [],
        ], $overrides);
    }

    public function test_creating_a_tables_event_persists_tables_and_mode(): void
    {
        $host = $this->organizer();

        $this->actingAs($host)->post(route('host.events.store'), $this->payload())->assertRedirect();

        $event = Event::where('title', 'Gala Dinner')->firstOrFail();
        $this->assertSame('tables', $event->ticketing_mode);
        $this->assertFalse((bool) $event->seating_enabled, 'tables mode is GA-style checkout, not seat-picking');
        $this->assertTrue((bool) $event->auto_assign_tables);
        $this->assertCount(2, $event->seatingTables);
        $this->assertSame('rect', $event->seatingTables()->where('name', 'Table 2')->value('shape'));
    }

    public function test_disabled_mode_is_forced_to_general_for_organizers(): void
    {
        Setting::putArray('ticketing_modes', ['general' => true, 'reserved' => true, 'tables' => false]);
        $host = $this->organizer();

        $this->actingAs($host)->post(route('host.events.store'), $this->payload())->assertRedirect();

        $event = Event::where('title', 'Gala Dinner')->firstOrFail();
        $this->assertSame('general', $event->ticketing_mode, 'a disabled mode falls back to general admission');
        $this->assertCount(0, $event->seatingTables, 'no tables are saved when the mode is not allowed');
    }

    public function test_auto_assign_endpoint_seats_unassigned_tickets(): void
    {
        $host = $this->organizer();
        $event = Event::create(['user_id' => $host->id, 'title' => 'Dinner', 'slug' => 'dinner', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'ticketing_mode' => 'tables']);
        $event->seatingTables()->create(['name' => 'A', 'capacity' => 2]);
        $event->seatingTables()->create(['name' => 'B', 'capacity' => 2]);

        $order = Order::create(['reference' => 'DRSVP-DIN001', 'event_id' => $event->id, 'status' => 'paid', 'total' => 0]);
        foreach (range(1, 3) as $n) {
            $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => "Guest {$n}", 'status' => 'valid']);
        }

        $this->actingAs($host)->post(route('host.events.seating.auto-assign', $event))->assertRedirect();

        $this->assertSame(3, $event->tickets()->whereNotNull('seating_table_id')->count());
    }

    public function test_service_respects_table_capacity(): void
    {
        $host = $this->organizer();
        $event = Event::create(['user_id' => $host->id, 'title' => 'Small', 'slug' => 'small', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'ticketing_mode' => 'tables']);
        $event->seatingTables()->create(['name' => 'Only', 'capacity' => 2]);

        $order = Order::create(['reference' => 'DRSVP-SML001', 'event_id' => $event->id, 'status' => 'paid', 'total' => 0]);
        foreach (range(1, 3) as $n) {
            $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => "G{$n}", 'status' => 'valid']);
        }

        $seated = app(TableAssignmentService::class)->autoAssign($event->fresh());

        $this->assertSame(2, $seated, 'only two fit at a 2-seat table');
        $this->assertSame(1, $event->tickets()->whereNull('seating_table_id')->count());
    }
}
