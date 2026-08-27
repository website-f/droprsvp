<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertInertia(fn (Assert $p) => $p
                ->component('public/page')
                ->where('page.title', 'About')
                ->where('seo.description', 'Learn about us')
                ->where('schema.@type', 'WebPage'));
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
}
