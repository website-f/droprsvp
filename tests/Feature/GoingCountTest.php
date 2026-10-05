<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Support\EventFaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Going" counts tickets, not buyers.
 *
 * Four people buying seven tickets means seven going, and the list says so:
 * the four names it knows, then "3 other guests".
 */
class GoingCountTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::create([
            'user_id' => $this->organizer()->id, 'title' => 'Clocktower', 'slug' => 'clocktower',
            'status' => 'published', 'published_at' => now(), 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addWeek(), 'show_participants' => true,
        ]);
    }

    /** A settled order with its tickets, the way checkout leaves them. */
    private function buy(string $name, int $tickets = 1, string $status = 'valid'): Order
    {
        $email = str($name)->slug()->append('@example.test')->value();

        $order = Order::create([
            'reference' => 'G-'.$name, 'event_id' => $this->event->id, 'status' => 'paid',
            'buyer_name' => $name, 'buyer_email' => $email, 'total' => 10, 'currency' => 'MYR', 'paid_at' => now(),
        ]);

        foreach (range(1, $tickets) as $ignored) {
            $order->tickets()->create([
                'event_id' => $this->event->id, 'attendee_name' => $name,
                'attendee_email' => $email, 'status' => $status,
            ]);
        }

        return $order;
    }

    public function test_four_buyers_with_seven_tickets_are_seven_going(): void
    {
        $this->buy('Aisha', 3);
        $this->buy('Ben');
        $this->buy('Chong', 2);
        $this->buy('Dina');

        $this->get('/en-my/e/clocktower/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('participants.count', 7)
            // The four it can name, and the three extra seats as others.
            ->has('participants.list', 4)
            ->where('participants.others', 3));
    }

    public function test_a_refunded_ticket_stops_counting(): void
    {
        $order = $this->buy('Aisha', 3);
        $this->buy('Ben');
        $order->tickets()->limit(2)->update(['status' => 'refunded']);

        $this->get('/en-my/e/clocktower/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('participants.count', 2)
            ->where('participants.others', 0));
    }

    public function test_the_card_count_matches_the_event_page(): void
    {
        $this->buy('Aisha', 3);
        $this->buy('Ben');

        // The browse card shows the same total beside the faces.
        $this->get('/en-my/all/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('events.data.0.participants', 4)
            ->where('events.data.0.faces', ['Ben', 'Aisha']));
    }

    public function test_one_buyer_shows_one_face_not_three(): void
    {
        $this->buy('Aisha', 3);

        $this->assertSame([$this->event->id => ['Aisha']], EventFaces::for([$this->event->id]));
    }

    public function test_others_are_held_back_while_names_are_still_paywalled(): void
    {
        // Six named holders, eight tickets: a free viewer sees four names and
        // the paywall, with no "others" card double-counting the rest.
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $i => $name) {
            $this->buy($name, $i === 0 ? 3 : 1);
        }

        $this->get('/en-my/e/clocktower/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('participants.count', 8)
            ->has('participants.list', 4)
            ->where('participants.unlocked', false)
            ->where('participants.others', 0));

        // Premium sees every name, and then the two unnamed seats.
        $premium = User::factory()->create(['premium_until' => now()->addMonth()]);

        $this->actingAs($premium)->get('/en-my/e/clocktower/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('participants.list', 6)
            ->where('participants.others', 2));
    }

    public function test_a_transferred_ticket_is_listed_under_its_new_holder(): void
    {
        $order = $this->buy('Aisha', 2);
        $order->tickets()->limit(1)->update(['attendee_name' => 'Farah', 'attendee_email' => 'farah@example.test']);

        $premium = User::factory()->create(['premium_until' => now()->addMonth()]);

        $this->actingAs($premium)->get('/en-my/e/clocktower/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('participants.count', 2)
            ->has('participants.list', 2)
            ->where('participants.others', 0));
    }
}
