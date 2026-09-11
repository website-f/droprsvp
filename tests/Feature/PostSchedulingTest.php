<?php

namespace Tests\Feature;

use App\Models\CmsPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** WordPress-style scheduled publishing for blog posts. */
class PostSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Merdeka Sale 2026',
            'slug' => '',
            'excerpt' => '',
            'body' => '<p>Hello</p>',
            'cover_image' => '',
            'category' => '',
            'status' => 'draft',
            'published_at' => '',
            'seo' => [],
        ], $overrides);
    }

    public function test_a_post_can_be_scheduled_for_a_future_date(): void
    {
        $at = now()->addDays(2);

        $this->actingAs($this->admin())
            ->post('/admin/cms/posts', $this->payload(['status' => 'scheduled', 'published_at' => $at->toDateTimeString()]))
            ->assertRedirect();

        $post = CmsPost::firstOrFail();
        $this->assertSame('scheduled', $post->status);
        $this->assertTrue($post->isScheduled());
        $this->assertEquals($at->startOfMinute(), $post->published_at->startOfMinute());
    }

    public function test_a_scheduled_post_is_invisible_everywhere_until_its_date(): void
    {
        CmsPost::create([
            'title' => 'Future', 'slug' => 'future', 'body' => '<p>x</p>',
            'status' => 'scheduled', 'published_at' => now()->addDay(),
        ]);

        $this->get('/en-my/blog/future')->assertNotFound();
        $this->get('/en-my/blog')->assertOk()->assertInertia(fn ($p) => $p->count('posts.data', 0));
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/en-my/blog/future/', false);
    }

    /** The date is checked at query time too, so a late cron can't leak a post. */
    public function test_a_future_dated_published_post_is_still_held_back(): void
    {
        CmsPost::create([
            'title' => 'Early', 'slug' => 'early', 'body' => '<p>x</p>',
            'status' => 'published', 'published_at' => now()->addHour(),
        ]);

        $this->get('/en-my/blog/early')->assertNotFound();
        $this->get('/en-my/blog')->assertInertia(fn ($p) => $p->count('posts.data', 0));
    }

    public function test_the_scheduler_command_publishes_posts_that_are_due(): void
    {
        $due = CmsPost::create(['title' => 'Due', 'slug' => 'due', 'body' => '<p>x</p>', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $later = CmsPost::create(['title' => 'Later', 'slug' => 'later', 'body' => '<p>x</p>', 'status' => 'scheduled', 'published_at' => now()->addDay()]);

        $this->artisan('posts:publish-scheduled')->assertSuccessful();

        $this->assertSame('published', $due->fresh()->status);
        $this->assertSame('scheduled', $later->fresh()->status);
        $this->get('/en-my/blog/due')->assertOk();
    }

    public function test_scheduling_a_date_in_the_past_just_publishes_it(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/cms/posts', $this->payload(['status' => 'scheduled', 'published_at' => now()->subHour()->toDateTimeString()]))
            ->assertRedirect();

        $this->assertSame('published', CmsPost::firstOrFail()->status);
    }

    public function test_scheduling_requires_a_date(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/cms/posts', $this->payload(['status' => 'scheduled', 'published_at' => '']))
            ->assertSessionHasErrors('published_at');
    }

    public function test_editing_a_live_post_keeps_its_original_publish_date(): void
    {
        $original = now()->subWeek()->startOfMinute();
        $post = CmsPost::create(['title' => 'Old', 'slug' => 'old', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => $original]);

        $this->actingAs($this->admin())
            ->put("/admin/cms/posts/{$post->id}", $this->payload(['title' => 'Old', 'slug' => 'old', 'status' => 'published', 'published_at' => '']))
            ->assertRedirect();

        $this->assertEquals($original, $post->fresh()->published_at->startOfMinute());
    }

    public function test_a_draft_clears_the_publish_date(): void
    {
        $post = CmsPost::create(['title' => 'P', 'slug' => 'p', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($this->admin())
            ->put("/admin/cms/posts/{$post->id}", $this->payload(['title' => 'P', 'slug' => 'p', 'status' => 'draft']))
            ->assertRedirect();

        $this->assertSame('draft', $post->fresh()->status);
        $this->assertNull($post->fresh()->published_at);
    }
}
