<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_legal_editor_creates_and_lists_both_pages(): void
    {
        $this->actingAs($this->superadmin())->get(route('admin.site.legal'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/site/legal')->has('pages', 2));

        $this->assertDatabaseHas('cms_pages', ['slug' => 'privacy-policy', 'status' => 'published']);
        $this->assertDatabaseHas('cms_pages', ['slug' => 'terms', 'status' => 'published']);
    }

    public function test_superadmin_can_edit_legal_content_and_it_renders_publicly(): void
    {
        $admin = $this->superadmin();
        // Ensure pages exist.
        $this->actingAs($admin)->get(route('admin.site.legal'));

        $this->actingAs($admin)->post(route('admin.site.legal.save'), [
            'pages' => [
                ['slug' => 'privacy-policy', 'title' => 'Privacy Policy', 'body' => '<h2>Our privacy promise</h2>'],
                ['slug' => 'terms', 'title' => 'Terms & Conditions', 'body' => '<h2>Our terms</h2>'],
            ],
        ])->assertRedirect();

        $this->assertSame('<h2>Our privacy promise</h2>', CmsPage::where('slug', 'privacy-policy')->value('body'));

        $this->get('/privacy-policy')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/page')->where('page.title', 'Privacy Policy'));
    }

    public function test_legal_pages_are_hidden_from_the_generic_pages_list(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->get(route('admin.site.legal')); // create them
        CmsPage::create(['title' => 'Normal', 'slug' => 'normal', 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($admin)->get(route('admin.cms.pages.index'))
            ->assertInertia(function (Assert $p) {
                $slugs = collect($p->toArray()['props']['pages'])->pluck('slug');
                $this->assertTrue($slugs->contains('normal'));
                $this->assertFalse($slugs->contains('privacy-policy'));
                $this->assertFalse($slugs->contains('terms'));

                return $p;
            });
    }

    public function test_opening_a_legal_page_in_the_page_editor_redirects_to_the_legal_editor(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->get(route('admin.site.legal')); // create them
        $page = CmsPage::where('slug', 'privacy-policy')->first();

        $this->actingAs($admin)->get(route('admin.cms.pages.edit', $page->id))
            ->assertRedirect(route('admin.site.legal'));
    }
}
