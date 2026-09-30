<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Support\CustomFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The route out of the event builder.
 *
 * "Back" from the edit screen goes to /host/events, and that page is the one
 * that has grown the most — paid order counts, public URLs, share links, the
 * platform fee, attendee faces. Any one of those failing on a real event turns
 * a back button into an error, which is the hardest kind of bug to describe.
 *
 * So this builds an event with everything on it and walks the round trip.
 */
class EventBuilderNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function loadedEvent(User $host): Event
    {
        $category = EventCategory::create(['name' => 'Community', 'slug' => 'community-'.uniqid()]);

        $event = Event::create([
            'user_id' => $host->id,
            'category_id' => $category->id,
            'title' => 'Blood on the Clocktower Session',
            'slug' => 'clocktower-'.uniqid(),
            'status' => 'published',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'venue_name' => 'HOL Cafe',
            'city' => 'Kajang',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(3),
            'published_at' => now()->subDay(),
            'capacity' => 30,
            'cover_image' => 'https://img.test/cover.jpg',
            'banner_image' => 'https://img.test/banner.jpg',
            'gallery' => ['https://img.test/g1.jpg', 'https://img.test/g2.jpg'],
            'custom_fields' => CustomFields::sanitize([
                ['label' => 'Player level', 'type' => 'select', 'required' => true, 'options' => [
                    ['label' => 'Beginner'], ['label' => 'Experienced'],
                ]],
                ['label' => 'Anything we should know?', 'type' => 'textarea'],
            ]),
        ]);

        $type = TicketType::create([
            'event_id' => $event->id, 'name' => 'Session A', 'kind' => 'paid',
            'price' => 20, 'currency' => 'MYR', 'quantity' => 16, 'is_active' => true,
        ]);

        // Two paid orders, three tickets between them — the counts on the list
        // page are derived, and derived numbers are where things break.
        foreach ([2, 1] as $i => $quantity) {
            $order = Order::create([
                'reference' => 'DRSVP-N'.$i, 'event_id' => $event->id, 'status' => 'paid',
                'buyer_name' => "Buyer {$i}", 'buyer_email' => "b{$i}@example.test",
                'subtotal' => 20 * $quantity, 'fees' => 3, 'total' => 20 * $quantity,
                'currency' => 'MYR', 'paid_at' => now(),
            ]);

            for ($n = 0; $n < $quantity; $n++) {
                Ticket::create([
                    'order_id' => $order->id, 'ticket_type_id' => $type->id, 'event_id' => $event->id,
                    'qr_token' => 'tok-'.uniqid(), 'attendee_name' => "Buyer {$i}", 'status' => 'valid',
                ]);
            }
        }

        return $event;
    }

    public function test_the_builder_opens_and_back_returns_to_the_event_list(): void
    {
        $host = $this->organizer();
        $event = $this->loadedEvent($host);

        // Open the builder.
        $this->actingAs($host)->get("/host/events/{$event->slug}/edit")->assertSuccessful();

        // "Back" is a plain link to the list. If that page throws, the back
        // button is indistinguishable from a broken one.
        $this->actingAs($host)->get('/host/events')->assertSuccessful();
    }

    public function test_the_event_list_counts_paid_orders_not_tickets(): void
    {
        $host = $this->organizer();
        $this->loadedEvent($host);

        $this->actingAs($host)->get('/host/events')
            ->assertSuccessful()
            ->assertInertia(fn ($p) => $p->where('events.0.orders_count', 2));
    }

    public function test_a_draft_with_nothing_filled_in_does_not_break_either_page(): void
    {
        $host = $this->organizer();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Untitled', 'slug' => 'untitled-'.uniqid(),
            'status' => 'draft', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        $this->actingAs($host)->get("/host/events/{$event->slug}/edit")->assertSuccessful();
        $this->actingAs($host)->get('/host/events')->assertSuccessful();
    }

    public function test_an_event_whose_custom_fields_are_malformed_still_opens(): void
    {
        // Written straight to the column, bypassing the sanitiser — which is
        // how bad data actually arrives (an older release, a manual fix, a
        // half-finished import).
        $host = $this->organizer();
        $event = $this->loadedEvent($host);
        $event->forceFill(['custom_fields' => ['not-an-array-of-fields', 42, null]])->save();

        $this->actingAs($host)->get("/host/events/{$event->slug}/edit")->assertSuccessful();
        $this->actingAs($host)->get('/host/events')->assertSuccessful();
        $this->actingAs($host)->get("/host/events/{$event->slug}/attendees")->assertSuccessful();
    }

    public function test_the_attendees_page_opens_for_a_loaded_event(): void
    {
        $host = $this->organizer();
        $event = $this->loadedEvent($host);

        $this->actingAs($host)->get("/host/events/{$event->slug}/attendees")->assertSuccessful();
    }
}
