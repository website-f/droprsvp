<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FollowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_follow_and_unfollow_an_organizer(): void
    {
        $organizer = User::factory()->create();
        $fan = User::factory()->create();

        $this->actingAs($fan)->post("/organizers/{$organizer->id}/follow")->assertRedirect();
        $this->assertTrue($fan->fresh()->isFollowing($organizer));
        $this->assertSame(1, $organizer->followers()->count());

        $this->actingAs($fan)->post("/organizers/{$organizer->id}/follow")->assertRedirect();
        $this->assertFalse($fan->fresh()->isFollowing($organizer));
    }

    public function test_a_user_cannot_follow_themselves(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post("/organizers/{$user->id}/follow")->assertStatus(422);
    }

    public function test_organizer_profile_shows_their_events(): void
    {
        $organizer = User::factory()->create(['name' => 'Star Org']);
        Event::create([
            'user_id' => $organizer->id, 'title' => 'Profile Fest', 'slug' => 'profile-fest',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addWeek(),
        ]);

        $this->get("/o/{$organizer->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/organizer')
                ->where('organizer.id', $organizer->id)
                ->where('organizer.name', 'Star Org')
                ->has('upcoming', 1));
    }

    public function test_a_user_with_no_events_has_no_public_profile(): void
    {
        $u = User::factory()->create();
        $this->get("/o/{$u->id}")->assertNotFound();
    }

    public function test_the_following_feed_lists_followed_organizers_upcoming_events(): void
    {
        $organizer = User::factory()->create(['name' => 'Cool Org']);
        Event::create([
            'user_id' => $organizer->id, 'title' => 'Future Fest', 'slug' => 'future-fest',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addWeek(),
        ]);
        $fan = User::factory()->create();
        $fan->following()->attach($organizer->id);

        $this->actingAs($fan)->get('/following')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('following')
                ->has('organizers', 1)
                ->has('upcoming', 1)
                ->where('upcoming.0.title', 'Future Fest'));
    }
}
