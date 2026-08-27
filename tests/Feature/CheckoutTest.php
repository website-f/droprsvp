<?php

namespace Tests\Feature;

use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Event, 1: TicketType} */
    private function publishedEventWithTicket(array $ttOverrides = []): array
    {
        $user = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Checkout Event',
            'slug' => 'checkout-event',
            'status' => 'published',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(5),
        ]);
        $tt = $event->ticketTypes()->create(array_merge([
            'name' => 'GA', 'kind' => 'paid', 'price' => 30, 'quantity' => 100,
            'min_per_order' => 1, 'max_per_order' => 10, 'is_active' => true,
        ], $ttOverrides));

        return [$event, $tt];
    }

    public function test_paid_checkout_via_fake_gateway_reserves_then_issues_tickets(): void
    {
        Mail::fake();
        [$event, $tt] = $this->publishedEventWithTicket();

        $this->post(route('checkout.start', $event), [
            'items' => [['ticket_type_id' => $tt->id, 'quantity' => 2]],
        ])->assertRedirect();

        $order = Order::first();
        $this->assertSame('pending', $order->status);
        $this->assertEquals(60, $order->total);
        $this->assertSame(2, $tt->fresh()->sold, 'stock reserved on order creation');
        $this->assertSame(0, $order->tickets()->count(), 'tickets not issued until paid');

        // Buyer details → gateway hand-off (fake gateway returns its internal pay URL).
        $this->post(route('checkout.pay', $order), ['buyer_name' => 'Ali', 'buyer_email' => 'ali@example.com'])
            ->assertRedirect(route('checkout.fake', $order));

        // Settle (fake gateway "payment").
        $this->get(route('checkout.fake', $order))->assertRedirect(route('checkout.confirmation', $order));

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(2, $order->tickets()->count());
        $this->assertSame(2, $tt->fresh()->sold);

        Mail::assertSent(TicketsIssued::class, fn (TicketsIssued $m) => $m->hasTo('ali@example.com'));
    }

    public function test_settlement_is_idempotent(): void
    {
        Mail::fake();
        [$event, $tt] = $this->publishedEventWithTicket();
        $this->post(route('checkout.start', $event), ['items' => [['ticket_type_id' => $tt->id, 'quantity' => 1]]]);
        $order = Order::first();
        $this->post(route('checkout.pay', $order), ['buyer_name' => 'A', 'buyer_email' => 'a@b.com']);
        $this->get(route('checkout.fake', $order));
        $this->get(route('checkout.fake', $order)); // second hit must not double-issue

        $this->assertSame(1, $order->fresh()->tickets()->count());
        Mail::assertSent(TicketsIssued::class, 1); // emailed exactly once
    }

    public function test_oversell_is_prevented(): void
    {
        [$event, $tt] = $this->publishedEventWithTicket(['quantity' => 1]);

        $this->post(route('checkout.start', $event), [
            'items' => [['ticket_type_id' => $tt->id, 'quantity' => 2]],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, $tt->fresh()->sold);
    }

    public function test_free_tickets_settle_without_the_gateway(): void
    {
        Mail::fake();
        [$event, $tt] = $this->publishedEventWithTicket(['kind' => 'free', 'price' => 0]);

        $this->post(route('checkout.start', $event), ['items' => [['ticket_type_id' => $tt->id, 'quantity' => 1]]]);
        $order = Order::first();
        $this->assertEquals(0, $order->total);

        $this->post(route('checkout.pay', $order), ['buyer_name' => 'A', 'buyer_email' => 'a@b.com'])
            ->assertRedirect(route('checkout.confirmation', $order));

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame(1, $order->tickets()->count());
        Mail::assertSent(TicketsIssued::class);
    }
}
