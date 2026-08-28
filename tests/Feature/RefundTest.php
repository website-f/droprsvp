<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Event, 1: Order, 2: TicketType} */
    private function paidOrder(): array
    {
        $host = $this->organizer();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Refundable', 'slug' => 'refundable',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
        $tt = $event->ticketTypes()->create(['name' => 'GA', 'kind' => 'paid', 'price' => 30, 'quantity' => 100, 'sold' => 2]);
        $order = Order::create(['reference' => 'DRSVP-REF01', 'event_id' => $event->id, 'status' => 'paid', 'total' => 60, 'paid_at' => now(), 'payment_ref' => 'pay_x']);
        $order->items()->create(['ticket_type_id' => $tt->id, 'name' => 'GA', 'unit_price' => 30, 'quantity' => 2, 'line_total' => 60]);
        $order->tickets()->create(['event_id' => $event->id, 'ticket_type_id' => $tt->id, 'status' => 'valid']);
        $order->tickets()->create(['event_id' => $event->id, 'ticket_type_id' => $tt->id, 'status' => 'valid']);

        return [$event, $order, $tt];
    }

    public function test_owner_can_refund_a_paid_order(): void
    {
        [$event, $order, $tt] = $this->paidOrder();

        $this->actingAs($event->user)
            ->post(route('host.events.orders.refund', [$event, $order]))
            ->assertRedirect()
            ->assertSessionHas('flash_success');

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertNotNull($order->refunded_at);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(0, $tt->fresh()->sold, 'stock released');
    }

    public function test_refund_is_idempotent(): void
    {
        [$event, $order] = $this->paidOrder();
        $this->actingAs($event->user)->post(route('host.events.orders.refund', [$event, $order]));
        $this->actingAs($event->user)->post(route('host.events.orders.refund', [$event, $order]))
            ->assertSessionHas('flash_error'); // already refunded → not paid anymore
    }

    public function test_non_owner_cannot_refund(): void
    {
        [$event, $order] = $this->paidOrder();
        $intruder = $this->organizer();

        $this->actingAs($intruder)->post(route('host.events.orders.refund', [$event, $order]))->assertForbidden();
        $this->assertSame('paid', $order->fresh()->status);
    }
}
