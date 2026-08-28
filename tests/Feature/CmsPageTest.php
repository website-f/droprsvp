<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CmsPageTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_superadmin_can_create_and_publish_a_page_with_seo(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('admin.cms.pages.store'), [
            'title' => 'About Us',
            'body' => '<p>Hello world</p>',
            'publish' => true,
            'seo' => ['meta_description' => 'About our company', 'robots_index' => true, 'robots_follow' => true],
        ])->assertRedirect(route('admin.cms.pages.index'));

        $page = CmsPage::first();
        $this->assertSame('about-us', $page->slug);
        $this->assertSame('published', $page->status);
        $this->assertSame($admin->id, $page->author_id);
        $this->assertSame('About our company', $page->seo->meta_description);
    }

    public function test_published_page_renders_at_its_root_slug_with_seo(): void
    {
        $page = CmsPage::create(['title' => 'About', 'slug' => 'about', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);
        $page->seo()->create(['meta_description' => 'Learn about us', 'robots_index' => true, 'robots_follow' => true]);

        $this->get('/about')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/page')->where('page.title', 'About'))
            // SEO is now server-rendered into the HTML head (no JS needed).
            ->assertSee('<meta name="description" content="Learn about us">', false)
            ->assertSee('"@type":"WebPage"', false);
    }

    public function test_draft_page_is_404_for_guests(): void
    {
        CmsPage::create(['title' => 'Secret', 'slug' => 'secret', 'body' => '<p>x</p>', 'status' => 'draft']);
        $this->get('/secret')->assertNotFound();
    }

    public function test_unknown_slug_is_404(): void
    {
        $this->get('/no-such-page')->assertNotFound();
    }

    public function test_non_superadmin_cannot_access_the_cms(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.cms.pages.index'))->assertForbidden();
    }

    public function test_page_builder_layout_is_saved_and_rendered(): void
    {
        $layout = [[
            'id' => 's1',
            'title' => 'Welcome',
            'columns' => [
                ['blocks' => [['id' => 'b1', 'type' => 'richtext', 'html' => '<p>Hi there</p>']]],
                ['blocks' => [['id' => 'b2', 'type' => 'image', 'url' => 'https://example.com/x.jpg', 'alt' => 'pic']]],
            ],
        ]];

        $this->actingAs($this->superadmin())->post(route('admin.cms.pages.store'), [
            'title' => 'Home Layout',
            'body' => '<h2>Welcome</h2><p>Hi there</p>',
            'layout' => $layout,
            'publish' => true,
            'seo' => ['robots_index' => true, 'robots_follow' => true],
        ])->assertRedirect(route('admin.cms.pages.index'));

        $page = CmsPage::first();
        $this->assertIsArray($page->layout);
        $this->assertCount(1, $page->layout);
        $this->assertSame('Welcome', $page->layout[0]['title']);
        $this->assertCount(2, $page->layout[0]['columns']);

        $this->get('/'.$page->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/page')->has('page.layout', 1));
    }

    public function test_drop_builder_saves_layout_and_flags_the_page(): void
    {
        $admin = $this->superadmin();
        $page = CmsPage::create(['title' => 'Home', 'slug' => 'home-x', 'status' => 'draft', 'author_id' => $admin->id]);
        $layout = [['id' => 's1', 'title' => 'Hero', 'columns' => [['blocks' => [['id' => 'b1', 'type' => 'richtext', 'html' => '<p>Hi</p>']]]], 'settings' => ['cols' => 1]]];

        $this->actingAs($admin)->post(route('admin.cms.pages.builder.save', $page->id), [
            'title' => 'Home updated', 'layout' => $layout, 'body' => '<p>Hi</p>',
        ])->assertRedirect(route('admin.cms.pages.edit', $page->id));

        $page->refresh();
        $this->assertSame('Home updated', $page->title);
        $this->assertIsArray($page->layout);
        $this->assertNotNull($page->builder_edited_at);
    }

    public function test_publishing_with_add_to_menu_creates_a_header_link(): void
    {
        Cache::flush();

        $this->actingAs($this->superadmin())->post(route('admin.cms.pages.store'), [
            'title' => 'Contact',
            'body' => '<p>Reach us</p>',
            'publish' => true,
            'add_to_menu' => true,
            'seo' => ['robots_index' => true, 'robots_follow' => true],
        ])->assertRedirect();

        $this->assertDatabaseHas('menu_items', ['location' => 'header', 'url' => '/contact', 'label' => 'Contact']);
    }

    public function test_add_to_menu_without_publish_does_not_create_a_link(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.cms.pages.store'), [
            'title' => 'Later',
            'body' => '<p>x</p>',
            'publish' => false,
            'add_to_menu' => true,
            'seo' => ['robots_index' => true, 'robots_follow' => true],
        ])->assertRedirect();

        $this->assertDatabaseMissing('menu_items', ['url' => '/later']);
    }
}
