<?php

namespace Tests\Feature;

use App\Models\CmsCategory;
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

        // Edit — including the homepage appearance (icon / subtitle / colour).
        $this->actingAs($admin)->put("/admin/categories/{$cat->id}", [
            'name' => 'Concerts', 'slug' => 'concerts', 'icon' => 'guitar', 'blurb' => 'Live gigs', 'color' => '#a855f7',
        ])->assertRedirect();
        $fresh = $cat->fresh();
        $this->assertSame('Concerts', $fresh->name);
        $this->assertSame('concerts', $fresh->slug);
        $this->assertSame('guitar', $fresh->icon);
        $this->assertSame('Live gigs', $fresh->blurb);
        $this->assertSame('#a855f7', $fresh->color);

        // A bad colour is rejected (must be a #rrggbb hex).
        $this->actingAs($admin)->put("/admin/categories/{$cat->id}", ['name' => 'Concerts', 'color' => 'purple'])
            ->assertSessionHasErrors('color');

        // Delete — soft (recoverable from the Archive).
        $this->actingAs($admin)->delete("/admin/categories/{$cat->id}")->assertRedirect();
        $this->assertSoftDeleted('event_categories', ['id' => $cat->id]);
    }

    public function test_index_lists_categories_with_event_counts(): void
    {
        EventCategory::create(['name' => 'Food', 'slug' => 'food', 'sort_order' => 1]);

        $this->actingAs($this->superadmin())->get('/admin/categories')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/categories/index')->has('categories', 1));
    }

    public function test_browse_seo_renders_on_the_discover_page(): void
    {
        $this->actingAs($this->superadmin())
            ->post('/admin/categories/browse-seo', ['title' => 'All Events in Malaysia', 'description' => 'Find events near you.'])
            ->assertRedirect();
        EventCategory::create(['name' => 'Music', 'slug' => 'music', 'sort_order' => 1, 'content' => 'The best music events across Malaysia.']);

        // Every category's copy used to render here, on the unfiltered page.
        // That put the same block on /all, /all/music and /all/tech alike —
        // duplicate content, and it told a reader nothing about the category
        // they had just clicked. It belongs on the category's own page.
        $this->get('/en-my/all')->assertInertia(fn (Assert $p) => $p
            ->where('seo.title', 'All Events in Malaysia')
            ->has('categoryContent', 0));
    }

    public function test_a_category_page_carries_only_its_own_copy(): void
    {
        EventCategory::create(['name' => 'Music', 'slug' => 'music', 'sort_order' => 1, 'content' => 'The best music events across Malaysia.']);
        EventCategory::create(['name' => 'Tech', 'slug' => 'tech', 'sort_order' => 2, 'content' => 'Meetups, demos and hackathons.']);

        $this->get('/en-my/all/music')->assertInertia(fn (Assert $p) => $p
            ->has('categoryContent', 1)
            ->where('categoryContent.0.name', 'Music')
            // Rendered as HTML now; plain-text copy becomes a paragraph.
            ->where('categoryContent.0.content', '<p>The best music events across Malaysia.</p>'));
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

    public function test_superadmin_can_manage_post_categories(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post('/admin/post-categories', ['name' => 'News'])->assertRedirect();
        $cat = CmsCategory::firstWhere('name', 'News');
        $this->assertSame('news', $cat->slug);

        $this->actingAs($admin)->put("/admin/post-categories/{$cat->id}", ['name' => 'Updates', 'slug' => 'updates'])->assertRedirect();
        $this->assertSame('Updates', $cat->fresh()->name);
        $this->assertSame('updates', $cat->fresh()->slug);

        $this->actingAs($admin)->delete("/admin/post-categories/{$cat->id}")->assertRedirect();
        $this->assertSoftDeleted('cms_categories', ['id' => $cat->id]);

        $this->actingAs(User::factory()->create())->post('/admin/post-categories', ['name' => 'X'])->assertForbidden();
    }
}
