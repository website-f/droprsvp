<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RobotsAndAdminNoindexTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_robots_txt_defaults_block_admin_and_link_the_sitemap(): void
    {
        $res = $this->get('/robots.txt');
        $res->assertOk();
        $res->assertSee('Disallow: /admin', false);
        $res->assertSee('Sitemap:', false);
    }

    public function test_superadmin_can_override_robots_txt(): void
    {
        $this->actingAs($this->superadmin())
            ->post('/admin/site/robots', ['content' => "User-agent: *\nDisallow: /secret"])
            ->assertRedirect();

        $this->get('/robots.txt')->assertSee('Disallow: /secret', false);
    }

    public function test_admin_pages_are_noindex_nofollow(): void
    {
        $this->actingAs($this->superadmin())
            ->get('/admin/seo/events')
            ->assertSee('noindex, nofollow', false);
    }

    public function test_public_pages_remain_indexable(): void
    {
        // The homepage must not inherit the admin noindex.
        $this->get('/en-my')->assertDontSee('content="noindex, nofollow"', false);
    }
}
