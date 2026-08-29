<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventReviewTest extends TestCase
{
    use RefreshDatabase;

    private function event(?User $host = null): Event
    {
        $host ??= User::factory()->create();

        return Event::create([
            'user_id' => $host->id, 'title' => 'Reviewable', 'slug' => 'reviewable',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->subDay(),
        ]);
    }

    /** A user who holds a paid ticket for the event (so they may review). */
    private function attendee(Event $event): User
    {
        $user = User::factory()->create();
        Order::create([
            'reference' => 'R-'.Str::random(6), 'event_id' => $event->id, 'user_id' => $user->id,
            'status' => 'paid', 'buyer_email' => $user->email, 'total' => 10, 'currency' => 'MYR', 'paid_at' => now(),
        ]);

        return $user;
    }

    public function test_an_attendee_can_leave_a_review(): void
    {
        $event = $this->event();
        $user = $this->attendee($event);

        $this->actingAs($user)->post("/e/{$event->slug}/reviews", ['rating' => 5, 'body' => 'Great!'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('event_reviews', ['event_id' => $event->id, 'user_id' => $user->id, 'rating' => 5, 'body' => 'Great!']);
    }

    public function test_a_review_is_one_per_user_and_upserts(): void
    {
        $event = $this->event();
        $user = $this->attendee($event);

        $this->actingAs($user)->post("/e/{$event->slug}/reviews", ['rating' => 3]);
        $this->actingAs($user)->post("/e/{$event->slug}/reviews", ['rating' => 4, 'body' => 'Better']);

        $this->assertSame(1, $event->reviews()->count());
        $this->assertSame(4, $event->reviews()->first()->rating);
    }

    public function test_a_non_attendee_cannot_review(): void
    {
        $event = $this->event();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post("/e/{$event->slug}/reviews", ['rating' => 5])
            ->assertSessionHasErrors('rating');
        $this->assertSame(0, $event->reviews()->count());
    }

    public function test_the_owner_cannot_review_their_own_event(): void
    {
        $host = User::factory()->create();
        $event = $this->event($host);

        $this->actingAs($host)->post("/e/{$event->slug}/reviews", ['rating' => 5])->assertForbidden();
        $this->assertSame(0, $event->reviews()->count());
    }

    public function test_rating_must_be_between_1_and_5(): void
    {
        $event = $this->event();
        $user = $this->attendee($event);

        $this->actingAs($user)->post("/e/{$event->slug}/reviews", ['rating' => 9])->assertSessionHasErrors('rating');
    }

    public function test_reviews_appear_on_the_event_page(): void
    {
        $event = $this->event();
        $a = $this->attendee($event);
        $b = $this->attendee($event);
        $this->actingAs($a)->post("/e/{$event->slug}/reviews", ['rating' => 4]);
        $this->actingAs($b)->post("/e/{$event->slug}/reviews", ['rating' => 2]);

        $this->get('/e/'.$event->slug)->assertInertia(fn (Assert $p) => $p
            ->where('reviews.count', 2)
            ->has('reviews.list', 2));
    }
}
