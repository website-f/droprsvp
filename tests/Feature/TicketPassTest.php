<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketPassTest extends TestCase
{
    use RefreshDatabase;

    private function issuedTicket(): Ticket
    {
        $user = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id, 'title' => 'Pass Event', 'slug' => 'pass-event',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(2),
        ]);
        $order = Order::create([
            'reference' => 'DRSVP-PASS01', 'event_id' => $event->id, 'status' => 'paid',
            'buyer_name' => 'Ali', 'buyer_email' => 'ali@example.com', 'total' => 0,
        ]);

        return $order->tickets()->create([
            'event_id' => $event->id, 'attendee_name' => 'Ali', 'status' => 'valid',
        ]);
    }

    public function test_ticket_pass_renders_with_a_qr_code(): void
    {
        $ticket = $this->issuedTicket();

        $this->get("/tickets/{$ticket->qr_token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/ticket')
                ->where('ticket.attendee_name', 'Ali')
                ->where('ticket.event.title', 'Pass Event')
                ->where('qr', fn (string $svg) => str_contains($svg, '<svg'))
            );
    }

    public function test_unknown_token_is_404(): void
    {
        $this->get('/tickets/not-a-real-token')->assertNotFound();
    }
}
