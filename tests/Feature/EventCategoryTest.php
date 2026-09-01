<?php

namespace Tests\Feature;

use App\Models\EventCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EventCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_superadmin_can_add_edit_and_delete_categories(): void
    {
        $admin = $this->superadmin();

        // Add — slug auto-generated.
        $this->actingAs($admin)->post('/admin/categories', ['name' => 'Live Music'])->assertRedirect();
        $cat = EventCategory::firstWhere('name', 'Live Music');
        $this->assertSame('live-music', $cat->slug);

        // Edit.
        $this->actingAs($admin)->put("/admin/categories/{$cat->id}", ['name' => 'Concerts', 'slug' => 'concerts'])->assertRedirect();
        $this->assertSame('Concerts', $cat->fresh()->name);
        $this->assertSame('concerts', $cat->fresh()->slug);

        // Delete.
        $this->actingAs($admin)->delete("/admin/categories/{$cat->id}")->assertRedirect();
        $this->assertDatabaseMissing('event_categories', ['id' => $cat->id]);
    }

    public function test_index_lists_categories_with_event_counts(): void
    {
        EventCategory::create(['name' => 'Food', 'slug' => 'food', 'sort_order' => 1]);

        $this->actingAs($this->superadmin())->get('/admin/categories')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/categories/index')->has('categories', 1));
    }

    public function test_browse_seo_and_category_content_render_on_the_discover_page(): void
    {
        $this->actingAs($this->superadmin())
            ->post('/admin/categories/browse-seo', ['title' => 'All Events in Malaysia', 'description' => 'Find events near you.'])
            ->assertRedirect();
        EventCategory::create(['name' => 'Music', 'slug' => 'music', 'sort_order' => 1, 'content' => 'The best music events across Malaysia.']);

        $this->get('/en-my/all')->assertInertia(fn (Assert $p) => $p
            ->where('seo.title', 'All Events in Malaysia')
            ->has('categoryContent', 1)
            ->where('categoryContent.0.content', 'The best music events across Malaysia.'));
    }

    public function test_superadmin_can_reorder_categories(): void
    {
        $admin = $this->superadmin();
        $a = EventCategory::create(['name' => 'A', 'slug' => 'a', 'sort_order' => 0]);
        $b = EventCategory::create(['name' => 'B', 'slug' => 'b', 'sort_order' => 1]);
        $c = EventCategory::create(['name' => 'C', 'slug' => 'c', 'sort_order' => 2]);

        // New order: C, A, B.
        $this->actingAs($admin)->post('/admin/categories/reorder', ['ids' => [$c->id, $a->id, $b->id]])->assertRedirect();

        $this->assertSame(0, $c->fresh()->sort_order);
        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(2, $b->fresh()->sort_order);
    }

    public function test_a_non_superadmin_cannot_manage_categories(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/categories')->assertForbidden();
        $this->actingAs(User::factory()->create())->post('/admin/categories', ['name' => 'X'])->assertForbidden();
    }
}
