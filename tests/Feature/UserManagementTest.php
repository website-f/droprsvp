<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function staff(): User
    {
        Role::findOrCreate('staff', 'web');
        $u = User::factory()->create();
        $u->assignRole('staff');

        return $u;
    }

    public function test_superadmin_can_add_a_user_with_a_temp_password_that_must_be_changed(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.users.store'), [
            'name' => 'New Staffer', 'email' => 'newstaff@example.test', 'role' => 'staff',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $u = User::where('email', 'newstaff@example.test')->first();
        $this->assertNotNull($u);
        $this->assertTrue((bool) $u->must_set_password);
        $this->assertTrue($u->hasRole('staff'));
        $this->assertNotNull($u->email_verified_at);
    }

    public function test_newly_added_user_is_forced_to_set_a_password_on_first_visit(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $this->actingAs($this->superadmin())->post(route('admin.users.store'), [
            'name' => 'Temp', 'email' => 'temp@example.test', 'role' => 'normal',
        ]);
        $u = User::where('email', 'temp@example.test')->first();

        $this->actingAs($u)->get('/dashboard')->assertRedirect(route('password.set'));
    }

    public function test_a_superadmin_cannot_change_their_own_role(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->post(route('admin.users.role', $admin), ['role' => 'normal'])
            ->assertSessionHas('flash_error');
        $this->assertTrue($admin->fresh()->hasRole('superadmin'));
    }

    public function test_staff_cannot_reach_a_section_they_are_not_granted(): void
    {
        // Grant staff only 'overview'.
        Setting::putArray('role_permissions', ['staff' => ['overview']]);
        $staff = $this->staff();

        $this->actingAs($staff)->get('/admin/overview')->assertOk();
        $this->actingAs($staff)->get('/admin/users')->assertForbidden();
        $this->actingAs($staff)->get('/admin/finance')->assertForbidden();
    }

    public function test_staff_can_reach_a_granted_section(): void
    {
        Setting::putArray('role_permissions', ['staff' => ['overview', 'analytics']]);
        $staff = $this->staff();

        $this->actingAs($staff)->get('/admin/analytics')->assertOk();
    }

    public function test_only_superadmin_can_save_the_permission_matrix(): void
    {
        Setting::putArray('role_permissions', ['staff' => ['settings']]);
        $staff = $this->staff();

        // Even with the settings section granted, staff can't edit the matrix.
        $this->actingAs($staff)->post(route('admin.settings.permissions'), ['permissions' => ['staff' => ['users']]])
            ->assertForbidden();

        $this->actingAs($this->superadmin())->post(route('admin.settings.permissions'), ['permissions' => ['staff' => ['overview', 'users']]])
            ->assertRedirect();
        $this->assertEqualsCanonicalizing(['overview', 'users'], Setting::getArray('role_permissions')['staff']);
    }

    public function test_staff_cannot_promote_a_user_to_superadmin(): void
    {
        Setting::putArray('role_permissions', ['staff' => ['users']]);
        $staff = $this->staff();
        $target = User::factory()->create();

        $this->actingAs($staff)->post(route('admin.users.role', $target), ['role' => 'superadmin'])
            ->assertForbidden();
        $this->assertFalse($target->fresh()->hasRole('superadmin'));
    }
}
