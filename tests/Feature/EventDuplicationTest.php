<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventDuplicationTest extends TestCase
{
    use RefreshDatabase;

    private function eventWithTickets(User $host): Event
    {
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Weekly Yoga', 'slug' => 'weekly-yoga',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(2), 'published_at' => now(),
        ]);
        TicketType::create(['event_id' => $event->id, 'name' => 'Standard', 'price' => 20, 'currency' => 'MYR', 'quantity' => 50, 'sold' => 12, 'is_active' => true]);
        $event->sessions()->create(['title' => 'Morning', 'starts_at' => now()->addDays(2), 'sort_order' => 0]);

        return $event;
    }

    public function test_duplicating_an_event_creates_a_draft_copy_with_ticket_types_reset(): void
    {
        $host = $this->organizer();
        $event = $this->eventWithTickets($host);

        $this->actingAs($host)->post("/host/events/{$event->slug}/duplicate")
            ->assertRedirect();

        $copy = Event::where('user_id', $host->id)->where('id', '!=', $event->id)->first();
        $this->assertNotNull($copy);
        $this->assertSame('Weekly Yoga (Copy)', $copy->title);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertNotSame($event->slug, $copy->slug);

        // Ticket types + sessions carried over, with sales reset.
        $this->assertSame(1, $copy->ticketTypes()->count());
        $this->assertSame(0, (int) $copy->ticketTypes()->first()->sold);
        $this->assertSame(20.0, (float) $copy->ticketTypes()->first()->price);
        $this->assertSame(1, $copy->sessions()->count());
    }

    public function test_the_copy_has_none_of_the_originals_orders(): void
    {
        $host = $this->organizer();
        $event = $this->eventWithTickets($host);
        Order::create(['reference' => 'DRSVP-ORIG1', 'event_id' => $event->id, 'status' => 'paid', 'total' => 20, 'currency' => 'MYR', 'paid_at' => now()]);

        $this->actingAs($host)->post("/host/events/{$event->slug}/duplicate");

        $copy = Event::where('id', '!=', $event->id)->latest('id')->first();
        $this->assertSame(0, $copy->orders()->count());
    }

    public function test_a_non_owner_cannot_duplicate_an_event(): void
    {
        $event = $this->eventWithTickets($this->organizer());

        $this->actingAs($this->organizer())->post("/host/events/{$event->slug}/duplicate")->assertForbidden();
    }
}
