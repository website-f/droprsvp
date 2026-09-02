<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\OrganizerPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizerDiscussionTest extends TestCase
{
    use RefreshDatabase;

    private function organizerWithEvent(): User
    {
        $host = $this->organizer();
        Event::create(['user_id' => $host->id, 'title' => 'Gig', 'slug' => 'gig-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        $host->ensureSlug();

        return $host;
    }

    public function test_signed_in_user_can_post_to_the_discussion(): void
    {
        $host = $this->organizerWithEvent();
        $visitor = User::factory()->create();

        $this->actingAs($visitor)->post("/o/{$host->slug}/discussion", ['body' => 'Do you host beginner events?'])->assertRedirect();
        $this->assertDatabaseHas('organizer_posts', ['organizer_id' => $host->id, 'user_id' => $visitor->id, 'body' => 'Do you host beginner events?']);

        $this->get("/o/{$host->slug}")->assertInertia(fn (Assert $p) => $p->has('discussion', 1)
            ->where('discussion.0.body', 'Do you host beginner events?'));
    }

    public function test_organizer_reply_is_flagged(): void
    {
        $host = $this->organizerWithEvent();
        $post = OrganizerPost::create(['organizer_id' => $host->id, 'user_id' => User::factory()->create()->id, 'body' => 'Hi!']);

        $this->actingAs($host)->post("/o/{$host->slug}/discussion", ['body' => 'Yes we do!', 'parent_id' => $post->id])->assertRedirect();

        $this->get("/o/{$host->slug}")->assertInertia(fn (Assert $p) => $p
            ->where('discussion.0.replies.0.body', 'Yes we do!')
            ->where('discussion.0.replies.0.is_organizer', true));
    }

    public function test_guests_cannot_post(): void
    {
        $host = $this->organizerWithEvent();
        $this->post("/o/{$host->slug}/discussion", ['body' => 'spam'])->assertRedirect(route('login'));
    }
}
