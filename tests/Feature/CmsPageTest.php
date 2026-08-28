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

    public function test_page_seo_keywords_are_saved_and_rendered(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('admin.cms.pages.store'), [
            'title' => 'Keyworded',
            'publish' => true,
            'seo' => ['meta_keywords' => 'alpha, beta, gamma', 'robots_index' => true, 'robots_follow' => true],
        ])->assertRedirect(route('admin.cms.pages.index'));

        $page = CmsPage::where('slug', 'keyworded')->first();
        $this->assertSame('alpha, beta, gamma', $page->seo->meta_keywords);

        $this->get('/'.$page->slug)->assertSee('<meta name="keywords" content="alpha, beta, gamma">', false);
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

    public function test_drop_builder_saves_puck_data_and_renders_publicly(): void
    {
        $admin = $this->superadmin();
        $page = CmsPage::create(['title' => 'Home', 'slug' => 'home-x', 'status' => 'published', 'published_at' => now(), 'author_id' => $admin->id]);

        $data = ['root' => [], 'content' => [
            ['type' => 'Hero', 'props' => ['id' => 'hero-1', 'title' => 'Welcome to DropRSVP', 'subtitle' => 'Find events']],
        ]];

        $this->actingAs($admin)->post(route('admin.cms.pages.builder.save', $page->id), [
            'data' => $data,
        ])->assertRedirect(route('admin.cms.pages.edit', $page->id));

        $page->refresh();
        $this->assertSame('Hero', $page->puck_data['content'][0]['type']);
        $this->assertSame('Welcome to DropRSVP', $page->puck_data['content'][0]['props']['title']);
        // A plain-text snapshot is stored for search/excerpts/SEO.
        $this->assertStringContainsString('Welcome to DropRSVP', (string) $page->body);
        $this->assertNotNull($page->builder_edited_at);

        // Public page receives the structured Puck data to render with the shared widgets.
        $this->get('/'.$page->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/page')
                ->where('page.puck.content.0.props.title', 'Welcome to DropRSVP'));
    }

    public function test_drop_builder_save_returns_json_for_the_fetch_client(): void
    {
        $admin = $this->superadmin();
        $page = CmsPage::create(['title' => 'Home', 'slug' => 'home-json', 'status' => 'draft', 'author_id' => $admin->id]);

        $this->actingAs($admin)
            ->postJson(route('admin.cms.pages.builder.save', $page->id), [
                'data' => ['root' => [], 'content' => []],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_metadata_save_does_not_wipe_builder_content(): void
    {
        $admin = $this->superadmin();
        $puck = ['root' => [], 'content' => [['type' => 'Text', 'props' => ['id' => 't1', 'text' => 'Built body']]]];
        $page = CmsPage::create(['title' => 'Home', 'slug' => 'home-y', 'status' => 'draft', 'author_id' => $admin->id, 'body' => 'Built body', 'puck_data' => $puck, 'builder_edited_at' => now()]);
        $page->seo()->create(['robots_index' => true, 'robots_follow' => true]);

        $this->actingAs($admin)->put(route('admin.cms.pages.update', $page->id), [
            'title' => 'Home renamed', 'publish' => true, 'seo' => ['robots_index' => true, 'robots_follow' => true],
        ])->assertRedirect(route('admin.cms.pages.index'));

        $page->refresh();
        $this->assertSame('Home renamed', $page->title);
        $this->assertSame('Built body', $page->body);                                    // preserved
        $this->assertSame('Built body', $page->puck_data['content'][0]['props']['text']); // preserved
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
