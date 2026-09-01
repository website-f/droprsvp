<?php

namespace Tests\Feature;

use App\Models\EventCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_archive_lists_trashed_items_restores_and_purges(): void
    {
        $admin = $this->superadmin();
        $cat = EventCategory::create(['name' => 'Temp', 'slug' => 'temp', 'sort_order' => 1]);
        $cat->delete(); // soft

        // It appears in the Archive under its type.
        $this->actingAs($admin)->get('/admin/archive?type=event-categories')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/archive/index')
                ->where('type', 'event-categories')
                ->has('items', 1)
                ->where('items.0.id', $cat->id));

        // Restore.
        $this->actingAs($admin)->post('/admin/archive/event-categories/restore', ['ids' => [$cat->id]])->assertRedirect();
        $this->assertNotSoftDeleted('event_categories', ['id' => $cat->id]);

        // Soft-delete again, then purge permanently.
        $cat->delete();
        $this->actingAs($admin)->post('/admin/archive/event-categories/delete', ['ids' => [$cat->id]])->assertRedirect();
        $this->assertDatabaseMissing('event_categories', ['id' => $cat->id]);
    }

    public function test_admin_can_soft_delete_a_user_and_restore_from_archive(): void
    {
        $admin = $this->superadmin();
        $u = User::factory()->create();

        $this->actingAs($admin)->delete("/admin/users/{$u->id}")->assertRedirect();
        $this->assertSoftDeleted('users', ['id' => $u->id]);

        $this->actingAs($admin)->get('/admin/archive?type=users')
            ->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.id', $u->id));

        $this->actingAs($admin)->post('/admin/archive/users/restore', ['ids' => [$u->id]])->assertRedirect();
        $this->assertNotSoftDeleted('users', ['id' => $u->id]);
    }

    public function test_cannot_delete_self_or_a_superadmin(): void
    {
        $admin = $this->superadmin();
        $other = $this->superadmin();

        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertRedirect();
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);

        $this->actingAs($admin)->delete("/admin/users/{$other->id}")->assertRedirect();
        $this->assertNotSoftDeleted('users', ['id' => $other->id]);
    }

    public function test_unknown_type_404s_and_non_superadmin_is_forbidden(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->post('/admin/archive/widgets/restore', ['ids' => [1]])->assertNotFound();

        $this->actingAs(User::factory()->create())->get('/admin/archive')->assertForbidden();
    }
}
