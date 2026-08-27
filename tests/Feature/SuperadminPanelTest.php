<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperadminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_overview_shows_platform_stats(): void
    {
        $admin = $this->superadmin();
        $host = User::factory()->create();
        Event::create(['user_id' => $host->id, 'title' => 'E', 'slug' => 'e1', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);

        $this->actingAs($admin)->get(route('admin.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/overview')->where('stats.events', 1)->has('fee_percent'));
    }

    public function test_superadmin_can_update_the_platform_fee(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('admin.settings.fee'), ['fee_percent' => 8.5])->assertRedirect();
        $this->assertEquals('8.5', Setting::get('platform_fee_percent'));
    }

    public function test_all_events_and_users_lists_render(): void
    {
        $admin = $this->superadmin();
        $host = User::factory()->create(['name' => 'Hosty']);
        Event::create(['user_id' => $host->id, 'title' => 'Findable', 'slug' => 'findable', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);

        $this->actingAs($admin)->get(route('admin.events.index'))
            ->assertInertia(fn (Assert $p) => $p->component('admin/events/index')->has('events.data'));

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $p) => $p->component('admin/users/index')->has('users.data'));
    }

    public function test_superadmin_can_grant_and_revoke_admin_but_not_self(): void
    {
        $admin = $this->superadmin();
        $target = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.users.superadmin', $target));
        $this->assertTrue($target->fresh()->hasRole('superadmin'));

        $this->actingAs($admin)->post(route('admin.users.superadmin', $target));
        $this->assertFalse($target->fresh()->hasRole('superadmin'));

        // Can't change your own role.
        $this->actingAs($admin)->post(route('admin.users.superadmin', $admin))->assertSessionHas('flash_error');
        $this->assertTrue($admin->fresh()->hasRole('superadmin'));
    }

    public function test_non_superadmin_is_blocked(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.overview'))->assertForbidden();
    }
}
