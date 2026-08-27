<?php

namespace Tests\Feature;

use App\Models\CmsCategory;
use App\Models\CmsPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CmsPostTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_superadmin_can_publish_a_post_and_category_is_created(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('admin.cms.posts.store'), [
            'title' => 'Our First Post',
            'excerpt' => 'A short intro',
            'body' => '<p>Body text</p>',
            'category' => 'News',
            'publish' => true,
            'seo' => ['meta_description' => 'Read our news', 'robots_index' => true, 'robots_follow' => true],
        ])->assertRedirect(route('admin.cms.posts.index'));

        $post = CmsPost::first();
        $this->assertSame('our-first-post', $post->slug);
        $this->assertSame('published', $post->status);
        $this->assertSame('News', $post->category->name);
        $this->assertSame(1, CmsCategory::where('slug', 'news')->count());
    }

    public function test_blog_index_lists_published_posts(): void
    {
        CmsPost::create(['title' => 'Live', 'slug' => 'live', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);
        CmsPost::create(['title' => 'Hidden', 'slug' => 'hidden', 'body' => '<p>x</p>', 'status' => 'draft']);

        $this->get('/blog')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/blog/index')->has('posts.data', 1));
    }

    public function test_published_article_renders_with_article_schema(): void
    {
        $author = User::factory()->create(['name' => 'Writer']);
        $post = CmsPost::create(['title' => 'Deep Dive', 'slug' => 'deep-dive', 'body' => '<p>content</p>', 'status' => 'published', 'published_at' => now(), 'author_id' => $author->id]);
        $post->seo()->create(['meta_description' => 'A deep dive', 'robots_index' => true, 'robots_follow' => true]);

        $this->get('/blog/deep-dive')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('public/blog/show')
                ->where('post.title', 'Deep Dive')
                ->where('post.author', 'Writer')
                ->where('schema.@type', 'BlogPosting'));
    }

    public function test_draft_article_is_404(): void
    {
        CmsPost::create(['title' => 'Draft', 'slug' => 'draft-post', 'body' => '<p>x</p>', 'status' => 'draft']);
        $this->get('/blog/draft-post')->assertNotFound();
    }

    public function test_non_superadmin_cannot_manage_posts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.cms.posts.index'))->assertForbidden();
    }
}
