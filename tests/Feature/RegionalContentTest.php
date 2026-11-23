<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\CmsPost;
use App\Models\Event;
use App\Models\HelpArticle;
use App\Models\User;
use App\Support\SeoTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Content per country: region versions of pages, legal pages and help
 * articles (same address, own text), blog posts for chosen countries, SEO
 * settings per country, the sitemap, the organizers' country filter and
 * analytics by region.
 */
class RegionalContentTest extends TestCase
{
    use RefreshDatabase;

    /** An absolute URL with its trailing slash kept (url() trims it). */
    private function abs(string $path): string
    {
        return rtrim(url('/'), '/').$path;
    }

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function page(string $title, array $attrs = []): CmsPage
    {
        return CmsPage::create(array_merge([
            'title' => $title, 'slug' => Str::slug($title), 'body' => '<p>'.$title.' body</p>',
            'status' => 'published', 'published_at' => now()->subDay(),
        ], $attrs));
    }

    private function event(string $region, string $title): Event
    {
        return Event::create([
            'user_id' => User::factory()->create()->id, 'title' => $title, 'slug' => Str::slug($title),
            'status' => 'published', 'visibility' => 'public', 'region' => $region, 'city' => 'Somewhere',
            'timezone' => $region === 'en-sg' ? 'Asia/Singapore' : 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addWeek(),
        ]);
    }

    // ---- pages ---------------------------------------------------------------------------

    public function test_a_region_version_replaces_the_page_at_the_same_address(): void
    {
        $main = $this->page('Pricing');
        $this->page('Pricing Singapore', ['slug' => null, 'parent_id' => $main->id, 'regions' => ['en-sg']]);

        $this->get('/en-sg/pricing/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('page.title', 'Pricing Singapore'))
            ->assertSee('<link rel="canonical" href="'.$this->abs('/en-sg/pricing/').'"', false);

        $this->get('/en-my/pricing/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('page.title', 'Pricing'))
            ->assertSee('<link rel="canonical" href="'.$this->abs('/en-my/pricing/').'"', false);

        // Elsewhere: the main page, pointing at the one global address.
        $this->get('/en-th/pricing/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('page.title', 'Pricing'))
            ->assertSee('<link rel="canonical" href="'.$this->abs('/en-my/pricing/').'"', false);
    }

    public function test_a_page_shown_only_in_some_regions_is_not_found_elsewhere(): void
    {
        $this->page('Singapore Promo', ['regions' => ['en-sg']]);

        $this->get('/en-sg/singapore-promo/')->assertOk();
        $this->get('/en-my/singapore-promo/')->assertNotFound();
    }

    public function test_the_admin_adds_a_region_version_and_a_region_can_only_have_one(): void
    {
        $admin = $this->superadmin();
        $main = $this->page('Pricing');

        $this->actingAs($admin)->post("/admin/cms/pages/{$main->id}/versions", ['regions' => ['en-sg', 'en-id']])
            ->assertSessionHasNoErrors();

        $version = $main->versions()->first();
        $this->assertSame(['en-id', 'en-sg'], $version->regions);
        $this->assertSame('draft', $version->status, 'a copy to edit, not live yet');
        $this->assertNull($version->slug);
        $this->assertSame('Pricing', $version->title);

        // A draft version is not served — Singapore still sees the main page.
        $this->get('/en-sg/pricing/')->assertInertia(fn (Assert $p) => $p->where('page.title', 'Pricing'));

        $this->actingAs($admin)->post("/admin/cms/pages/{$main->id}/versions", ['regions' => ['en-sg']])
            ->assertSessionHasErrors('regions');
        $this->assertSame(1, $main->versions()->count());

        // Deleting the version gives its regions the main page back.
        $this->actingAs($admin)->delete("/admin/cms/pages/{$version->id}")->assertRedirect(route('admin.cms.pages.edit', $main->id));
        $this->assertSame(0, $main->versions()->count());
        $this->assertNotNull($main->fresh());
    }

    // ---- legal ---------------------------------------------------------------------------

    public function test_legal_pages_get_region_versions_from_the_legal_editor(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->get('/admin/site/legal')->assertOk();

        $this->actingAs($admin)->post('/admin/site/legal', ['pages' => [[
            'slug' => 'terms', 'title' => 'Terms & Conditions', 'body' => '<p>Malaysian terms</p>',
            'versions' => [['regions' => ['en-sg'], 'title' => 'Terms (Singapore)', 'body' => '<p>Singapore terms</p>']],
        ]]])->assertSessionHasNoErrors();

        $this->get('/en-sg/terms/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('page.title', 'Terms (Singapore)'));
        $this->get('/en-my/terms/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('page.title', 'Terms & Conditions'));

        // Saving again with the version's id edits it rather than adding another.
        $main = CmsPage::main()->where('slug', 'terms')->first();
        $id = $main->versions()->value('id');
        $this->actingAs($admin)->post('/admin/site/legal', ['pages' => [[
            'slug' => 'terms', 'title' => 'Terms & Conditions', 'body' => '<p>Malaysian terms</p>',
            'versions' => [['id' => $id, 'regions' => ['en-sg', 'en-id'], 'title' => 'Terms (SG + ID)', 'body' => '<p>x</p>']],
        ]]])->assertSessionHasNoErrors();
        $this->assertSame(1, $main->versions()->count());
        $this->get('/en-id/terms/')->assertInertia(fn (Assert $p) => $p->where('page.title', 'Terms (SG + ID)'));

        // A region in two versions is refused.
        $this->actingAs($admin)->post('/admin/site/legal', ['pages' => [[
            'slug' => 'terms', 'title' => 'Terms & Conditions', 'body' => '',
            'versions' => [
                ['regions' => ['en-sg'], 'title' => 'A', 'body' => ''],
                ['regions' => ['en-sg'], 'title' => 'B', 'body' => ''],
            ],
        ]]])->assertSessionHasErrors('pages.0.versions');

        // Leaving the version out removes it.
        $this->actingAs($admin)->post('/admin/site/legal', ['pages' => [[
            'slug' => 'terms', 'title' => 'Terms & Conditions', 'body' => '<p>Malaysian terms</p>', 'versions' => [],
        ]]])->assertSessionHasNoErrors();
        $this->assertSame(0, $main->versions()->count());
        $this->get('/en-sg/terms/')->assertInertia(fn (Assert $p) => $p->where('page.title', 'Terms & Conditions'));
    }

    // ---- help ----------------------------------------------------------------------------

    public function test_a_help_article_has_region_versions_too(): void
    {
        $main = HelpArticle::create(['title' => 'Getting paid', 'slug' => 'getting-paid', 'category' => 'Payouts', 'body' => '<p>MY</p>', 'status' => 'published']);
        HelpArticle::create(['parent_id' => $main->id, 'regions' => ['en-sg'], 'title' => 'Getting paid in Singapore', 'slug' => null, 'category' => 'Payouts', 'body' => '<p>SG</p>', 'status' => 'published']);
        HelpArticle::create(['title' => 'Only in Thailand', 'slug' => 'only-th', 'category' => 'Payouts', 'regions' => ['en-th'], 'status' => 'published']);

        $this->get('/en-sg/help/getting-paid/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('article.title', 'Getting paid in Singapore'));
        $this->get('/en-my/help/getting-paid/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('article.title', 'Getting paid'));
        $this->get('/en-my/help/only-th/')->assertNotFound();

        $this->get('/en-my/help/')->assertOk()->assertDontSee('Only in Thailand');
        $this->get('/en-th/help/')->assertOk()->assertSee('Only in Thailand');
    }

    // ---- blog ----------------------------------------------------------------------------

    public function test_a_post_for_some_countries_only_appears_there(): void
    {
        CmsPost::create(['title' => 'Best gigs in Singapore', 'slug' => 'sg-gigs', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()->subDay(), 'regions' => ['en-sg']]);
        CmsPost::create(['title' => 'How to host an event', 'slug' => 'how-to-host', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()->subDay()]);

        $this->get('/en-sg/blog/')->assertOk()->assertSee('Best gigs in Singapore')->assertSee('How to host an event');
        $this->get('/en-my/blog/')->assertOk()->assertDontSee('Best gigs in Singapore')->assertSee('How to host an event');

        // Its link from another country lands on its own country's copy.
        $this->get('/en-my/blog/sg-gigs/')->assertRedirect($this->abs('/en-sg/blog/sg-gigs/'));
        $this->get('/en-sg/blog/sg-gigs/')->assertOk()
            ->assertSee('<link rel="canonical" href="'.$this->abs('/en-sg/blog/sg-gigs/').'"', false);
    }

    public function test_the_admin_sets_a_posts_countries(): void
    {
        $this->actingAs($this->superadmin())->post('/admin/cms/posts', [
            'title' => 'Jakarta weekend', 'status' => 'published', 'regions' => ['en-id'], 'seo' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['en-id'], CmsPost::where('title', 'Jakarta weekend')->first()->regions);

        $this->actingAs($this->superadmin())->post('/admin/cms/posts', [
            'title' => 'Nowhere', 'status' => 'published', 'regions' => ['en-zz'], 'seo' => [],
        ])->assertSessionHasErrors('regions.0');
    }

    // ---- SEO per country -------------------------------------------------------------------

    public function test_homepage_seo_can_be_changed_per_country_and_the_rest_comes_from_the_default(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->post('/admin/site/home-seo', ['title' => 'Events everywhere', 'description' => 'Default description'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/site/home-seo', ['region' => 'en-sg', 'title' => 'Singapore events', 'description' => ''])->assertSessionHasNoErrors();

        $sg = $this->get('/en-sg/')->assertOk()->getContent();
        $this->assertStringContainsString('Singapore events', $sg);
        $this->assertStringContainsString('Default description', $sg, 'a blank field uses the default');

        $my = $this->get('/en-my/')->assertOk()->getContent();
        $this->assertStringContainsString('Events everywhere', $my);
        $this->assertStringNotContainsString('Singapore events', $my);

        // The admin sees the country's own value on its tab, the default behind it.
        $this->actingAs($admin)->get('/admin/site/home-seo?region=en-sg')->assertInertia(fn (Assert $p) => $p
            ->where('seo.title', 'Singapore events')
            ->where('seo.description', '')
            ->where('inherited.description', 'Default description')
            ->where('region', 'en-sg'));
    }

    public function test_event_seo_templates_can_differ_per_country(): void
    {
        $this->actingAs($this->superadmin())->post('/admin/seo/templates', ['region' => 'en-sg', 'event_title' => '{event_name} — Singapore tickets'])
            ->assertSessionHasNoErrors();
        SeoTemplate::forget();

        $this->assertSame('Chilli Crab Fest — Singapore tickets', SeoTemplate::forEvent('event_title', $this->event('en-sg', 'Chilli Crab Fest')));
        $this->assertStringNotContainsString('Singapore tickets', (string) SeoTemplate::forEvent('event_title', $this->event('en-my', 'Rendang Fest')));
    }

    // ---- sitemap ---------------------------------------------------------------------------

    public function test_the_sitemap_lists_each_regions_pages(): void
    {
        $main = $this->page('Pricing');
        $this->page('Pricing Singapore', ['slug' => null, 'parent_id' => $main->id, 'regions' => ['en-sg']]);
        CmsPost::create(['title' => 'SG only', 'slug' => 'sg-only', 'status' => 'published', 'published_at' => now()->subDay(), 'regions' => ['en-sg']]);
        CmsPost::create(['title' => 'Everywhere', 'slug' => 'everywhere', 'status' => 'published', 'published_at' => now()->subDay()]);

        $pages = $this->get('/page-sitemap.xml')->assertOk()->getContent();
        foreach (['/en-my/', '/en-sg/', '/en-sg/all/', '/en-th/blog/', '/en-my/pricing/', '/en-sg/pricing/'] as $path) {
            $this->assertStringContainsString('<loc>'.$this->abs($path).'</loc>', $pages, $path);
        }

        $posts = $this->get('/post-sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>'.$this->abs('/en-sg/blog/sg-only/').'</loc>', $posts);
        $this->assertStringNotContainsString('/en-my/blog/sg-only/', $posts);
        $this->assertStringContainsString('<loc>'.$this->abs('/en-my/blog/everywhere/').'</loc>', $posts);
    }

    // ---- organizers ------------------------------------------------------------------------

    public function test_organizers_are_filtered_by_country(): void
    {
        Mail::fake();
        $sg = $this->organizer();
        $this->actingAs($sg)->post('/host/apply', ['business_name' => 'Lion City Gigs', 'email' => 'a@sg.test', 'phone' => '6512345678', 'country' => 'SG'])
            ->assertSessionHasNoErrors();
        // No country given: guessed from where they are browsing from.
        $my = $this->organizer();
        $this->actingAs($my)->withHeader('CF-IPCountry', 'MY')->post('/host/apply', ['business_name' => 'KL Nights', 'email' => 'b@my.test', 'phone' => '60123456789'])
            ->assertSessionHasNoErrors();

        $this->assertSame('SG', $sg->fresh()->organizerProfile->country);
        $this->assertSame('MY', $my->fresh()->organizerProfile->country);

        $this->actingAs($this->superadmin())->get('/admin/organizers?status=all&country=SG')->assertInertia(fn (Assert $p) => $p
            ->has('applications.data', 1)
            ->where('applications.data.0.business_name', 'Lion City Gigs')
            ->where('applications.data.0.country.name', 'Singapore')
            ->where('filters.country', 'SG')
            ->has('countries', 2));
    }

    // ---- analytics -------------------------------------------------------------------------

    public function test_admin_analytics_can_be_narrowed_to_one_region(): void
    {
        $this->event('en-sg', 'Marina Bay Trivia');
        $this->event('en-my', 'KL Quiz');
        $this->event('en-my', 'Penang Quiz');
        $admin = $this->superadmin();

        $this->actingAs($admin)->get('/admin/analytics')->assertInertia(fn (Assert $p) => $p->where('kpis.events', 3));
        $this->actingAs($admin)->get('/admin/analytics?region=en-sg')->assertInertia(fn (Assert $p) => $p
            ->where('kpis.events', 1)
            ->where('filters.region', 'en-sg')
            ->has('events.data', 1));
    }

    // ---- auto-detect -----------------------------------------------------------------------

    public function test_the_home_page_follows_the_visitors_country_from_the_cdn(): void
    {
        $this->withHeader('CF-IPCountry', 'TH')->get('/')->assertRedirect($this->abs('/en-th/'));
        // A country without a region falls back to the default.
        $this->withHeader('CF-IPCountry', 'FR')->get('/')->assertRedirect($this->abs('/en-my/'));
    }
}
