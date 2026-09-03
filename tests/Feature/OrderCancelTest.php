<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCancelTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $host, array $overrides = []): Event
    {
        return Event::create(array_merge([
            'user_id' => $host->id, 'title' => 'Meetup', 'slug' => 'meetup-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(5),
        ], $overrides));
    }

    private function freeOrder(Event $event, User $buyer, int $qty = 1): Order
    {
        $tt = TicketType::create([
            'event_id' => $event->id, 'name' => 'Free', 'price' => 0, 'currency' => 'MYR',
            'quantity' => 100, 'sold' => $qty, 'is_active' => true,
        ]);
        $order = Order::create([
            'reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'paid',
            'total' => 0, 'currency' => 'MYR', 'paid_at' => now(),
            'buyer_name' => $buyer->name, 'buyer_email' => $buyer->email, 'user_id' => $buyer->id,
        ]);
        $order->items()->create(['ticket_type_id' => $tt->id, 'name' => 'Free', 'unit_price' => 0, 'quantity' => $qty, 'line_total' => 0]);
        for ($i = 0; $i < $qty; $i++) {
            Ticket::create([
                'order_id' => $order->id, 'ticket_type_id' => $tt->id, 'event_id' => $event->id,
                'qr_token' => 'qr-'.uniqid(), 'attendee_name' => $buyer->name, 'status' => 'valid',
            ]);
        }

        return $order;
    }

    public function test_buyer_cancels_a_free_registration_and_seats_are_released(): void
    {
        $host = $this->organizer();
        $buyer = User::factory()->create();
        $order = $this->freeOrder($this->event($host), $buyer, 2);
        $tt = $order->items->first()->ticket_type_id;

        $this->actingAs($buyer)->post("/my/orders/{$order->reference}/cancel")
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, Ticket::where('order_id', $order->id)->where('status', 'valid')->count());
        $this->assertSame(0, (int) TicketType::find($tt)->sold);
        $this->assertTrue(AppNotification::where('user_id', $host->id)->where('type', 'order')->exists());
    }

    public function test_a_paid_order_cannot_be_cancelled_this_way(): void
    {
        $buyer = User::factory()->create();
        $order = Order::create([
            'reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $this->event($this->organizer())->id, 'status' => 'paid',
            'total' => 50, 'currency' => 'MYR', 'paid_at' => now(), 'user_id' => $buyer->id, 'buyer_email' => $buyer->email,
        ]);

        $this->actingAs($buyer)->post("/my/orders/{$order->reference}/cancel")->assertStatus(422);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_a_free_registration_cannot_be_cancelled_after_the_event_starts(): void
    {
        $buyer = User::factory()->create();
        $event = $this->event($this->organizer(), ['starts_at' => now()->subDay()]);
        $order = $this->freeOrder($event, $buyer);

        $this->actingAs($buyer)->post("/my/orders/{$order->reference}/cancel")->assertStatus(422);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_a_stranger_cannot_cancel_someone_elses_registration(): void
    {
        $order = $this->freeOrder($this->event($this->organizer()), User::factory()->create());

        $this->actingAs(User::factory()->create())->post("/my/orders/{$order->reference}/cancel")->assertForbidden();
    }
}
