<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HostFinanceInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function eventFor($host, array $overrides = []): Event
    {
        return Event::create(array_merge([
            'user_id' => $host->id, 'title' => 'Gala', 'slug' => 'gala-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ], $overrides));
    }

    private function paidOrder(Event $event, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id,
            'status' => 'paid', 'total' => 100, 'paid_at' => now(), 'buyer_name' => 'Jane Doe', 'buyer_email' => 'jane@example.test',
        ], $overrides));
    }

    public function test_organizer_finance_overview_shows_balance_and_per_event_breakdown(): void
    {
        Config::set('droprsvp.platform_fee_percent', 10);
        $host = $this->organizer();
        $event = $this->eventFor($host);
        $this->paidOrder($event);

        $this->actingAs($host)->get('/host/finance')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('host/finance')
                ->where('balance.gross', 100)
                ->where('balance.fee', 10)
                ->where('balance.net', 90)
                ->has('events', 1)
                ->where('events.0.gross', 100)
                ->where('events.0.net', 90));
    }

    public function test_organizer_invoices_hub_lists_events_and_payouts(): void
    {
        $host = $this->organizer();
        $event = $this->eventFor($host);
        $this->paidOrder($event, ['total' => 50]);

        $this->actingAs($host)->get('/host/invoices')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('host/invoices/index')
                ->has('events.data', 1)
                ->where('events.data.0.revenue', 50)
                ->where('events.data.0.invoices', 1)
                ->has('payouts', 0));
    }

    public function test_organizer_sees_attendee_invoices_for_their_event(): void
    {
        $host = $this->organizer();
        $event = $this->eventFor($host);
        $order = $this->paidOrder($event);

        $this->actingAs($host)->get("/host/invoices/events/{$event->slug}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('host/invoices/event')
                ->where('event.gross', 100)
                ->has('orders.data', 1)
                ->where('orders.data.0.reference', $order->reference)
                ->where('orders.data.0.buyer', 'Jane Doe'));
    }

    public function test_non_owner_cannot_view_another_organizers_event_invoices(): void
    {
        $host = $this->organizer();
        $event = $this->eventFor($host);

        $this->actingAs($this->organizer())->get("/host/invoices/events/{$event->slug}")->assertForbidden();
    }

    public function test_organizer_can_download_an_attendee_invoice_for_their_own_event(): void
    {
        $host = $this->organizer();
        $event = $this->eventFor($host);
        $order = $this->paidOrder($event);

        // The organizer isn't the buyer, but owns the event → allowed.
        $this->actingAs($host)->get("/my/orders/{$order->reference}/receipt")->assertOk();
    }

    public function test_a_stranger_cannot_download_an_attendee_invoice(): void
    {
        $host = $this->organizer();
        $event = $this->eventFor($host);
        $order = $this->paidOrder($event);

        $this->actingAs($this->organizer())->get("/my/orders/{$order->reference}/receipt")->assertForbidden();
    }
}
