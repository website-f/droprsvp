<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // the public nav is cached; keep tests deterministic
    }

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_superadmin_can_view_the_menu_manager(): void
    {
        $this->actingAs($this->superadmin())
            ->get(route('admin.cms.menu.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/cms/menu/index')->has('items')->has('builtins'));
    }

    public function test_non_superadmin_cannot_access_the_menu_manager(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.cms.menu.index'))
            ->assertForbidden();
    }

    public function test_superadmin_can_add_a_menu_item(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('admin.cms.menu.store'), ['label' => 'About us', 'url' => '/about', 'new_tab' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('menu_items', ['label' => 'About us', 'url' => '/about', 'location' => 'header']);
    }

    public function test_menu_items_are_shared_to_public_pages_in_order(): void
    {
        MenuItem::create(['location' => 'header', 'label' => 'Blog', 'url' => '/blog', 'sort' => 2]);
        MenuItem::create(['location' => 'header', 'label' => 'Home', 'url' => '/', 'sort' => 1]);

        $this->get('/')->assertInertia(fn (Assert $p) => $p
            ->component('welcome')
            ->has('nav', 2)
            ->where('nav.0.label', 'Home')   // ordered by sort
            ->where('nav.1.label', 'Blog'));
    }

    public function test_superadmin_can_reorder_items(): void
    {
        $a = MenuItem::create(['location' => 'header', 'label' => 'A', 'url' => '/a', 'sort' => 0]);
        $b = MenuItem::create(['location' => 'header', 'label' => 'B', 'url' => '/b', 'sort' => 1]);

        $this->actingAs($this->superadmin())
            ->post(route('admin.cms.menu.reorder'), ['ids' => [$b->id, $a->id]])
            ->assertRedirect();

        $this->assertSame(0, $b->fresh()->sort);
        $this->assertSame(1, $a->fresh()->sort);
    }

    public function test_superadmin_can_update_and_delete_items(): void
    {
        $item = MenuItem::create(['location' => 'header', 'label' => 'Old', 'url' => '/old', 'sort' => 0]);
        $admin = $this->superadmin();

        $this->actingAs($admin)
            ->put(route('admin.cms.menu.update', $item), ['label' => 'New', 'url' => '/new', 'new_tab' => true])
            ->assertRedirect();
        $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'label' => 'New', 'url' => '/new', 'new_tab' => true]);

        $this->actingAs($admin)
            ->delete(route('admin.cms.menu.destroy', $item))
            ->assertRedirect();
        $this->assertDatabaseMissing('menu_items', ['id' => $item->id]);
    }
}
