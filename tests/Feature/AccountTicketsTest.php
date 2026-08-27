<?php

namespace Tests\Feature;

use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountTicketsTest extends TestCase
{
    use RefreshDatabase;

    private function event(): Event
    {
        $host = User::factory()->create();

        return Event::create([
            'user_id' => $host->id, 'title' => 'Launch', 'slug' => 'launch',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
    }

    private function paidOrder(Event $event, array $attrs): Order
    {
        $order = Order::create(array_merge([
            'reference' => 'DRSVP-'.strtoupper(substr(md5(json_encode($attrs)), 0, 6)),
            'event_id' => $event->id, 'status' => 'paid', 'total' => 50, 'currency' => 'MYR',
        ], $attrs));
        Ticket::create(['order_id' => $order->id, 'event_id' => $event->id, 'attendee_name' => 'Guest', 'status' => 'valid']);

        return $order;
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('account.tickets'))->assertRedirect();
    }

    public function test_buyer_sees_orders_by_account_and_by_email_but_not_others(): void
    {
        $event = $this->event();
        $buyer = User::factory()->create(['email' => 'me@example.com']);

        $this->paidOrder($event, ['user_id' => $buyer->id, 'buyer_email' => 'me@example.com']); // owned by id
        $this->paidOrder($event, ['user_id' => null, 'buyer_email' => 'me@example.com']);        // guest w/ my email
        $this->paidOrder($event, ['user_id' => null, 'buyer_email' => 'someone@else.com']);      // not mine

        $this->actingAs($buyer)
            ->get(route('account.tickets'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('account/tickets')
                ->has('orders.data', 2));
    }

    public function test_buyer_can_resend_their_paid_order(): void
    {
        Mail::fake();
        $event = $this->event();
        $buyer = User::factory()->create(['email' => 'me@example.com']);
        $order = $this->paidOrder($event, ['user_id' => $buyer->id, 'buyer_email' => 'me@example.com']);

        $this->actingAs($buyer)
            ->post(route('account.orders.resend', $order))
            ->assertRedirect();

        Mail::assertSent(TicketsIssued::class, fn (TicketsIssued $m) => $m->hasTo('me@example.com'));
    }

    public function test_buyer_cannot_resend_someone_elses_order(): void
    {
        Mail::fake();
        $event = $this->event();
        $order = $this->paidOrder($event, ['user_id' => null, 'buyer_email' => 'someone@else.com']);

        $this->actingAs(User::factory()->create(['email' => 'me@example.com']))
            ->post(route('account.orders.resend', $order))
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_resend_rejects_unpaid_orders(): void
    {
        Mail::fake();
        $event = $this->event();
        $buyer = User::factory()->create(['email' => 'me@example.com']);
        $order = Order::create([
            'reference' => 'DRSVP-PEND01', 'user_id' => $buyer->id, 'buyer_email' => 'me@example.com',
            'event_id' => $event->id, 'status' => 'pending', 'total' => 50, 'currency' => 'MYR',
        ]);

        $this->actingAs($buyer)
            ->post(route('account.orders.resend', $order))
            ->assertStatus(422);

        Mail::assertNothingSent();
    }
}
