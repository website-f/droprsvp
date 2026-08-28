<?php

namespace Tests\Feature;

use App\Models\HelpArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_help_index_groups_published_articles_by_category(): void
    {
        HelpArticle::create(['category' => 'Tickets', 'title' => 'Buying', 'slug' => 'buying', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);
        HelpArticle::create(['category' => 'Tickets', 'title' => 'Draft one', 'slug' => 'draft-one', 'body' => '<p>x</p>', 'status' => 'draft']);

        $this->get('/help')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('public/help/index')
            ->has('categories', 1)
            ->where('categories.0.name', 'Tickets')
            ->has('categories.0.articles', 1));
    }

    public function test_help_article_renders_with_faq_schema(): void
    {
        HelpArticle::create(['category' => 'Tickets', 'title' => 'Refunds', 'slug' => 'refunds', 'body' => '<p>How refunds work.</p>', 'status' => 'published', 'published_at' => now()]);

        $this->get('/help/refunds')->assertOk()
            ->assertSee('"@type":"FAQPage"', false)
            ->assertInertia(fn (Assert $p) => $p->component('public/help/show')->where('article.title', 'Refunds'));
    }

    public function test_draft_help_article_is_404(): void
    {
        HelpArticle::create(['category' => 'X', 'title' => 'Secret', 'slug' => 'secret-help', 'body' => '<p>x</p>', 'status' => 'draft']);
        $this->get('/help/secret-help')->assertNotFound();
    }

    public function test_superadmin_can_create_a_help_article(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.cms.help.store'), [
            'title' => 'How to check in', 'category' => 'Organizing', 'body' => '<p>Scan the QR.</p>', 'publish' => true,
        ])->assertRedirect(route('admin.cms.help.index'));

        $this->assertDatabaseHas('help_articles', ['slug' => 'how-to-check-in', 'status' => 'published']);
    }

    public function test_superadmin_can_open_the_help_edit_page(): void
    {
        $article = HelpArticle::create(['category' => 'Tickets', 'title' => 'Editable', 'slug' => 'editable', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);

        // Admin edit binds by id (public routes bind by slug) — regression for a 404.
        $this->actingAs($this->superadmin())->get(route('admin.cms.help.edit', $article->id))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/cms/help/form')->where('article.title', 'Editable'));
    }

    public function test_non_superadmin_cannot_manage_help(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.cms.help.index'))->assertForbidden();
    }
}
