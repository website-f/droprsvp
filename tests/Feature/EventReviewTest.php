<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_a_signed_in_user_can_leave_a_review(): void
    {
        $event = $this->event();
        $user = User::factory()->create();

        $this->actingAs($user)->post("/e/{$event->slug}/reviews", ['rating' => 5, 'body' => 'Great!'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('event_reviews', ['event_id' => $event->id, 'user_id' => $user->id, 'rating' => 5, 'body' => 'Great!']);
    }

    public function test_a_review_is_one_per_user_and_upserts(): void
    {
        $event = $this->event();
        $user = User::factory()->create();

        $this->actingAs($user)->post("/e/{$event->slug}/reviews", ['rating' => 3]);
        $this->actingAs($user)->post("/e/{$event->slug}/reviews", ['rating' => 4, 'body' => 'Better']);

        $this->assertSame(1, $event->reviews()->count());
        $this->assertSame(4, $event->reviews()->first()->rating);
    }

    public function test_a_guest_must_log_in_to_review(): void
    {
        $event = $this->event();

        $this->post("/e/{$event->slug}/reviews", ['rating' => 5])->assertRedirect('/login');
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
        $user = User::factory()->create();

        $this->actingAs($user)->post("/e/{$event->slug}/reviews", ['rating' => 9])->assertSessionHasErrors('rating');
    }

    public function test_reviews_appear_on_the_event_page(): void
    {
        $event = $this->event();
        $this->actingAs(User::factory()->create())->post("/e/{$event->slug}/reviews", ['rating' => 4]);
        $this->actingAs(User::factory()->create())->post("/e/{$event->slug}/reviews", ['rating' => 2]);

        $this->get('/e/'.$event->slug)->assertInertia(fn (Assert $p) => $p
            ->where('reviews.count', 2)
            ->has('reviews.list', 2));
    }
}
