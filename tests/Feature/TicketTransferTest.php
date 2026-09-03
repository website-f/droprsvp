<?php

namespace Tests\Feature;

use App\Mail\TicketTransferred;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketTransferTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(User $buyer, array $eventOverrides = [], string $status = 'valid'): Ticket
    {
        $event = Event::create(array_merge([
            'user_id' => $this->organizer()->id, 'title' => 'Concert', 'slug' => 'concert-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(3),
        ], $eventOverrides));
        $order = Order::create([
            'reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'paid',
            'total' => 100, 'currency' => 'MYR', 'paid_at' => now(),
            'buyer_name' => $buyer->name, 'buyer_email' => $buyer->email, 'user_id' => $buyer->id,
        ]);

        return Ticket::create([
            'order_id' => $order->id, 'event_id' => $event->id, 'qr_token' => 'qr-'.uniqid(),
            'attendee_name' => $buyer->name, 'attendee_email' => $buyer->email, 'status' => $status,
        ]);
    }

    public function test_buyer_transfers_a_ticket_and_the_new_attendee_is_emailed(): void
    {
        Mail::fake();
        $buyer = User::factory()->create();
        $ticket = $this->ticket($buyer);
        $oldToken = $ticket->qr_token;

        $this->actingAs($buyer)->post("/my/tickets/{$ticket->qr_token}/transfer", [
            'to_name' => 'Aisha Rahman', 'to_email' => 'aisha@example.test',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $ticket->refresh();
        $this->assertSame('Aisha Rahman', $ticket->attendee_name);
        $this->assertSame('aisha@example.test', $ticket->attendee_email);
        $this->assertNotSame($oldToken, $ticket->qr_token); // old pass invalidated
        $this->assertDatabaseHas('ticket_transfers', [
            'ticket_id' => $ticket->id, 'to_email' => 'aisha@example.test', 'from_email' => $buyer->email,
        ]);
        Mail::assertSent(TicketTransferred::class, fn ($m) => $m->hasTo('aisha@example.test'));
    }

    public function test_a_stranger_cannot_transfer_someone_elses_ticket(): void
    {
        $ticket = $this->ticket(User::factory()->create());

        $this->actingAs(User::factory()->create())->post("/my/tickets/{$ticket->qr_token}/transfer", [
            'to_name' => 'X', 'to_email' => 'x@example.test',
        ])->assertForbidden();
    }

    public function test_a_checked_in_ticket_cannot_be_transferred(): void
    {
        $buyer = User::factory()->create();
        $ticket = $this->ticket($buyer, [], 'checked_in');

        $this->actingAs($buyer)->post("/my/tickets/{$ticket->qr_token}/transfer", [
            'to_name' => 'X', 'to_email' => 'x@example.test',
        ])->assertStatus(422);
    }

    public function test_a_ticket_for_a_past_event_cannot_be_transferred(): void
    {
        $buyer = User::factory()->create();
        $ticket = $this->ticket($buyer, ['starts_at' => now()->subDay()]);

        $this->actingAs($buyer)->post("/my/tickets/{$ticket->qr_token}/transfer", [
            'to_name' => 'X', 'to_email' => 'x@example.test',
        ])->assertStatus(422);
    }

    public function test_transfer_requires_a_valid_recipient_email(): void
    {
        $buyer = User::factory()->create();
        $ticket = $this->ticket($buyer);

        $this->actingAs($buyer)->post("/my/tickets/{$ticket->qr_token}/transfer", [
            'to_name' => 'No Email', 'to_email' => 'not-an-email',
        ])->assertStatus(302)->assertSessionHasErrors('to_email');
    }
}
