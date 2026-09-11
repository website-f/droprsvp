<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The landing page's social share image is superadmin-editable, with a default. */
class HomeShareImageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_the_homepage_falls_back_to_the_branded_default_image(): void
    {
        $this->get('/en-my')
            ->assertSee('<meta property="og:image" content="'.url('/og-default.png').'">', false)
            ->assertSee('<meta name="twitter:image" content="'.url('/og-default.png').'">', false);
    }

    public function test_the_editor_exposes_the_current_image_and_the_default_for_preview(): void
    {
        $this->actingAs($this->admin())->get('/admin/site/home-seo')->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/site/home-seo')
                ->where('seo.image', '')
                ->where('defaultImage', url('/og-default.png')));
    }

    public function test_a_saved_image_is_rendered_as_the_share_image(): void
    {
        $this->actingAs($this->admin())->post('/admin/site/home-seo', [
            'title' => 'Find your people',
            'description' => 'Discover events near you.',
            'keywords' => '',
            'image' => '/storage/uploads/share.png',
        ])->assertRedirect();

        $this->assertSame('/storage/uploads/share.png', Setting::getArray('home_seo')['image']);

        $this->get('/en-my')
            ->assertSee('<meta property="og:image" content="'.url('/storage/uploads/share.png').'">', false)
            ->assertSee('<meta name="twitter:image" content="'.url('/storage/uploads/share.png').'">', false);
    }

    public function test_clearing_the_image_returns_to_the_default(): void
    {
        Setting::putArray('home_seo', ['image' => '/storage/uploads/share.png']);

        $this->actingAs($this->admin())->post('/admin/site/home-seo', [
            'title' => '', 'description' => '', 'keywords' => '', 'image' => '',
        ])->assertRedirect();

        $this->get('/en-my')->assertSee('<meta property="og:image" content="'.url('/og-default.png').'">', false);
    }

    public function test_only_an_admin_can_change_it(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/site/home-seo', ['title' => 'x', 'description' => '', 'keywords' => '', 'image' => '/evil.png'])
            ->assertForbidden();
    }
}
