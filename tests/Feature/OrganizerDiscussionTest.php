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

        $this->get("/o/{$host->slug}")->assertInertia(fn (Assert $p) => $p->has('discussion.posts', 1)
            ->where('discussion.posts.0.body', 'Do you host beginner events?')
            ->where('discussion.pagination.total', 1));
    }

    public function test_organizer_reply_is_flagged(): void
    {
        $host = $this->organizerWithEvent();
        $post = OrganizerPost::create(['organizer_id' => $host->id, 'user_id' => User::factory()->create()->id, 'body' => 'Hi!']);

        $this->actingAs($host)->post("/o/{$host->slug}/discussion", ['body' => 'Yes we do!', 'parent_id' => $post->id])->assertRedirect();

        $this->get("/o/{$host->slug}")->assertInertia(fn (Assert $p) => $p
            ->where('discussion.posts.0.replies.0.body', 'Yes we do!')
            ->where('discussion.posts.0.replies.0.is_organizer', true));
    }

    public function test_admin_can_reply_on_behalf_of_organizer(): void
    {
        $host = $this->organizerWithEvent();
        $post = OrganizerPost::create(['organizer_id' => $host->id, 'user_id' => User::factory()->create()->id, 'body' => 'Question?']);
        $admin = User::factory()->create();
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->post("/o/{$host->slug}/discussion", ['body' => 'On behalf', 'parent_id' => $post->id, 'as_organizer' => true])->assertRedirect();

        // Posting "as organizer" stamps the organizer as author, so it carries the badge.
        $this->assertDatabaseHas('organizer_posts', ['organizer_id' => $host->id, 'user_id' => $host->id, 'body' => 'On behalf']);
        $this->get("/o/{$host->slug}")->assertInertia(fn (Assert $p) => $p
            ->where('discussion.posts.0.replies.0.is_organizer', true));
    }

    public function test_nested_replies_are_returned_as_a_tree(): void
    {
        $host = $this->organizerWithEvent();
        $u = User::factory()->create();
        $top = OrganizerPost::create(['organizer_id' => $host->id, 'user_id' => $u->id, 'body' => 'Top']);
        $reply = OrganizerPost::create(['organizer_id' => $host->id, 'user_id' => $u->id, 'parent_id' => $top->id, 'body' => 'Reply']);

        // A reply to a reply is allowed (chains of any depth).
        $this->actingAs($u)->post("/o/{$host->slug}/discussion", ['body' => 'Nested', 'parent_id' => $reply->id])->assertRedirect();

        $this->get("/o/{$host->slug}")->assertInertia(fn (Assert $p) => $p
            ->where('discussion.posts.0.replies.0.replies.0.body', 'Nested'));
    }

    public function test_non_moderator_cannot_post_as_organizer(): void
    {
        $host = $this->organizerWithEvent();
        $visitor = User::factory()->create();

        $this->actingAs($visitor)->post("/o/{$host->slug}/discussion", ['body' => 'Sneaky', 'as_organizer' => true])->assertRedirect();

        // The flag is ignored for non-moderators — author stays the visitor.
        $this->assertDatabaseHas('organizer_posts', ['organizer_id' => $host->id, 'user_id' => $visitor->id, 'body' => 'Sneaky']);
    }

    public function test_guests_cannot_post(): void
    {
        $host = $this->organizerWithEvent();
        $this->post("/o/{$host->slug}/discussion", ['body' => 'spam'])->assertRedirect(route('login'));
    }
}
