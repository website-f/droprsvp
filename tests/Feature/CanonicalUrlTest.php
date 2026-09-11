<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\CmsPost;
use App\Models\Event;
use App\Models\HelpArticle;
use App\Models\User;
use App\Support\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every indexable page lives at /en-my/…/ — locale-prefixed, trailing slash.
 * The pre-move URLs still resolve, but only as a permanent redirect.
 */
class CanonicalUrlTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $slug): CmsPage
    {
        return CmsPage::create(['title' => ucfirst($slug), 'slug' => $slug, 'body' => '<p>Hi</p>', 'status' => 'published', 'published_at' => now()]);
    }

    private function event(string $slug): Event
    {
        return Event::create([
            'user_id' => User::factory()->create()->id, 'title' => 'Ev', 'slug' => $slug,
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);
    }

    public function test_url_helper_builds_the_canonical_form(): void
    {
        $this->assertSame('/en-my/', Url::path());
        $this->assertSame('/en-my/blog/', Url::path('blog'));
        $this->assertSame('/en-my/blog/my-post/', Url::path('blog', 'my-post'));
        $this->assertSame('/en-my/e/gig/', Url::path('/e/', '/gig/'));
    }

    public function test_slash_leaves_files_and_query_strings_alone(): void
    {
        $this->assertSame('https://x.test/en-my/blog/', Url::slash('https://x.test/en-my/blog'));
        $this->assertSame('https://x.test/en-my/blog/', Url::slash('https://x.test/en-my/blog/'));
        $this->assertSame('https://x.test/sitemap.xml', Url::slash('https://x.test/sitemap.xml'));
        $this->assertSame('https://x.test/en-my/all/?q=jazz', Url::slash('https://x.test/en-my/all?q=jazz'));
    }

    public function test_content_pages_are_served_under_the_locale_prefix(): void
    {
        $this->get('/en-my/blog')->assertOk();
        $this->get('/en-my/help')->assertOk();
        $this->get('/en-my/contact')->assertOk();
    }

    /**
     * Asserts the exact Location header. assertRedirect() runs both sides through
     * url()->to(), which strips the trailing slash — so it would happily pass on
     * a redirect to the unslashed URL, which then costs a second Apache hop.
     */
    private function assertRedirectsTo(string $from, string $path): void
    {
        $res = $this->get($from);
        $res->assertStatus(301);
        $this->assertSame(rtrim(url('/'), '/').$path, $res->headers->get('Location'));
    }

    public function test_old_content_urls_permanently_redirect_to_the_canonical_form(): void
    {
        $this->assertRedirectsTo('/blog', '/en-my/blog/');
        $this->assertRedirectsTo('/help', '/en-my/help/');
        $this->assertRedirectsTo('/contact', '/en-my/contact/');
    }

    public function test_the_bare_root_lands_on_the_slashed_locale_home(): void
    {
        $res = $this->get('/');
        $this->assertSame(rtrim(url('/'), '/').'/en-my/', $res->headers->get('Location'));
    }

    public function test_old_detail_urls_permanently_redirect_too(): void
    {
        $this->event('gig');
        CmsPost::create(['title' => 'P', 'slug' => 'hello', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);
        HelpArticle::create(['category' => 'Tickets', 'title' => 'A', 'slug' => 'refunds', 'body' => 'x', 'status' => 'published']);
        $host = User::factory()->create();
        $host->ensureSlug();

        $this->assertRedirectsTo('/e/gig', '/en-my/e/gig/');
        $this->assertRedirectsTo('/blog/hello', '/en-my/blog/hello/');
        $this->assertRedirectsTo('/help/refunds', '/en-my/help/refunds/');
        $this->assertRedirectsTo('/o/'.$host->slug, '/en-my/o/'.$host->slug.'/');
    }

    public function test_a_cms_page_is_served_under_the_prefix_and_301s_from_its_old_root_slug(): void
    {
        $this->page('terms');

        $this->get('/en-my/terms')->assertOk();
        $this->assertRedirectsTo('/terms', '/en-my/terms/');
    }

    public function test_an_unknown_slug_still_404s_rather_than_redirecting(): void
    {
        $this->get('/en-my/not-a-page')->assertNotFound();
        $this->get('/not-a-page')->assertNotFound();
    }

    public function test_a_city_or_category_still_wins_over_a_page_of_the_same_name(): void
    {
        // Discovery owns the single-segment space first; the CMS only gets what's left.
        $this->get('/en-my/all')->assertOk();
    }

    public function test_canonical_tags_carry_the_trailing_slash(): void
    {
        $this->page('about');

        $base = rtrim(url('/'), '/');
        $this->get('/en-my/blog')->assertSee('<link rel="canonical" href="'.$base.'/en-my/blog/"', false);
        $this->get('/en-my/about')->assertSee('<link rel="canonical" href="'.$base.'/en-my/about/"', false);
        $this->get('/en-my')->assertSee('<link rel="canonical" href="'.$base.'/en-my/"', false);
    }

    public function test_an_event_canonical_points_at_the_locale_url(): void
    {
        $this->event('jazz');

        $this->get('/en-my/e/jazz')->assertSee('<link rel="canonical" href="'.rtrim(url('/'), '/').'/en-my/e/jazz/"', false);
    }
}
