<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Seat;
use App\Models\SeatTemplate;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeatingTest extends TestCase
{
    use RefreshDatabase;

    /** Create a published seated event (via the host controller) and return it. */
    private function seatedEvent(array $section = []): Event
    {
        $host = $this->organizer();
        $section = array_merge(['name' => 'VIP', 'kind' => 'seated', 'price' => 50, 'rows' => 2, 'cols' => 3, 'color' => '#111'], $section);

        $this->actingAs($host)->post('/host/events', [
            'title' => 'Big Concert', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'seating_enabled' => true, 'sections' => [$section], 'ticketTypes' => [], 'sessions' => [], 'publish' => true,
        ])->assertRedirect();

        return Event::where('title', 'Big Concert')->firstOrFail();
    }

    public function test_creating_a_seated_event_generates_sections_seats_and_a_backing_ticket_type(): void
    {
        $event = $this->seatedEvent();

        $this->assertTrue($event->seating_enabled);
        $this->assertSame(1, $event->seatSections()->count());
        $this->assertSame(6, $event->seats()->count());               // 2 rows × 3

        $section = $event->seatSections()->first();
        $this->assertNotNull($section->ticket_type_id);
        $this->assertSame(6, $section->ticketType->quantity);          // backing inventory
        $this->assertSame('A1', $event->seats()->orderBy('sort_order')->first()->label);
    }

    public function test_a_ga_section_uses_capacity_and_creates_no_seats(): void
    {
        $event = $this->seatedEvent(['kind' => 'ga', 'capacity' => 80, 'rows' => null, 'cols' => null]);

        $this->assertSame(0, $event->seats()->count());
        $this->assertSame(80, $event->seatSections()->first()->ticketType->quantity);
    }

    public function test_buyer_reserves_specific_seats_then_payment_issues_seated_tickets(): void
    {
        $event = $this->seatedEvent();
        $seatIds = $event->seats()->orderBy('sort_order')->limit(2)->pluck('id')->all();

        $this->post("/e/{$event->slug}/checkout", ['seats' => $seatIds])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(100.0, (float) $order->total);               // 2 × RM50
        $this->assertSame(2, Seat::whereIn('id', $seatIds)->where('status', 'held')->count());

        app(CheckoutService::class)->markPaid($order);

        $this->assertSame(2, $order->tickets()->count());
        $this->assertNotNull($order->tickets()->first()->seat_label);
        $this->assertSame(2, Seat::whereIn('id', $seatIds)->where('status', 'sold')->count());
    }

    public function test_a_held_seat_cannot_be_double_booked(): void
    {
        $event = $this->seatedEvent();
        $seat = $event->seats()->orderBy('sort_order')->first();

        $this->post("/e/{$event->slug}/checkout", ['seats' => [$seat->id]])->assertRedirect();
        $this->assertSame('held', $seat->fresh()->status);

        // A second buyer trying the same seat is rejected.
        $this->post("/e/{$event->slug}/checkout", ['seats' => [$seat->id]])->assertSessionHasErrors('seats');
        $this->assertSame(1, Order::count());
    }

    public function test_releasing_an_abandoned_order_frees_its_seats(): void
    {
        $event = $this->seatedEvent();
        $seatIds = $event->seats()->limit(2)->pluck('id')->all();
        $this->post("/e/{$event->slug}/checkout", ['seats' => $seatIds])->assertRedirect();
        $order = Order::latest('id')->firstOrFail();

        app(CheckoutService::class)->release($order);

        $this->assertSame(2, Seat::whereIn('id', $seatIds)->where('status', 'available')->count());
    }

    public function test_public_event_payload_exposes_the_seat_map_and_hides_backing_ticket_types(): void
    {
        $event = $this->seatedEvent();

        $this->get("/en-my/e/{$event->slug}")->assertInertia(fn ($p) => $p
            ->component('public/event')
            ->where('event.seating_enabled', true)
            ->has('event.seating', 1)
            ->has('event.seating.0.seats', 6)
            ->where('event.ticket_types', []));            // section ticket type not shown in the GA selector
    }

    public function test_layout_positions_stage_and_custom_row_labels_are_saved(): void
    {
        $host = $this->organizer();

        $this->actingAs($host)->post('/host/events', [
            'title' => 'Arena Show', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'seating_enabled' => true, 'ticketTypes' => [], 'sessions' => [], 'publish' => true,
            'sections' => [
                ['name' => 'STAGE', 'kind' => 'stage', 'color' => '#111', 'x' => 40, 'y' => 10, 'width' => 300, 'height' => 48],
                ['name' => 'Balcony', 'kind' => 'seated', 'price' => 80, 'rows' => 2, 'cols' => 4, 'x' => 60, 'y' => 200, 'row_label_start' => 'D'],
            ],
        ])->assertRedirect();

        $event = Event::where('title', 'Arena Show')->firstOrFail();

        $stage = $event->seatSections()->where('kind', 'stage')->firstOrFail();
        $this->assertNull($stage->ticket_type_id);              // stage sells nothing
        $this->assertSame(0, $stage->seats()->count());
        $this->assertSame(40, $stage->x);

        $balcony = $event->seatSections()->where('kind', 'seated')->firstOrFail();
        $this->assertSame(60, $balcony->x);
        $this->assertSame(200, $balcony->y);
        $this->assertSame('D1', $balcony->seats()->orderBy('sort_order')->first()->label);   // custom start letter
    }

    public function test_host_can_save_a_seating_template(): void
    {
        $host = $this->organizer();

        $this->actingAs($host)->post('/host/seat-templates', [
            'name' => 'Main Hall',
            'sections' => [['name' => 'Stalls', 'color' => '#111', 'kind' => 'seated', 'price' => 30, 'rows' => 4, 'cols' => 5]],
        ])->assertRedirect();

        $tpl = SeatTemplate::where('user_id', $host->id)->firstOrFail();
        $this->assertSame('Main Hall', $tpl->name);
        $this->assertSame('Stalls', $tpl->data[0]['name']);
    }
}
