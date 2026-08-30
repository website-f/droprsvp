<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\CmsPost;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves SEO is rendered into the HTML by Laravel (no JS/SSR): every assertion
 * reads the raw server response body, exactly what a crawler with JS disabled sees.
 */
class ServerSeoTest extends TestCase
{
    use RefreshDatabase;

    private function publishedEvent(array $overrides = []): Event
    {
        $host = User::factory()->create();
        $event = Event::create(array_merge([
            'user_id' => $host->id, 'title' => 'Neon Nights', 'slug' => 'neon-nights',
            'subtitle' => 'A rooftop live night', 'description' => 'Four acts under the stars.',
            'cover_image' => 'https://img.test/cover.jpg', 'status' => 'published',
            'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDays(10),
        ], $overrides));
        TicketType::create(['event_id' => $event->id, 'name' => 'GA', 'kind' => 'paid', 'price' => 65, 'currency' => 'MYR', 'quantity' => 100, 'is_active' => true]);

        return $event;
    }

    public function test_home_emits_website_and_organization_structured_data(): void
    {
        $res = $this->get('/en-my')->assertOk();
        $res->assertSee('"@type":"WebSite"', false);
        $res->assertSee('"@type":"Organization"', false);
        $res->assertSee('SearchAction', false);            // sitelinks search box
        $res->assertSee('<meta property="og:type" content="website">', false);
        $res->assertSee('<link rel="canonical"', false);
    }

    public function test_pages_without_an_image_get_the_default_og_image_and_org_logo(): void
    {
        $res = $this->get('/en-my')->assertOk();
        $res->assertSee(url('/og-default.png'), false);  // default share image
        $res->assertSee(url('/logo-mark.png'), false);   // Organization logo in JSON-LD
    }

    public function test_canonical_and_sitemap_use_the_trailing_slash_form(): void
    {
        $event = $this->publishedEvent();

        // Canonical + og:url carry the trailing slash that .htaccess enforces.
        $res = $this->get('/en-my')->assertOk();
        $res->assertSee('<link rel="canonical" href="'.url('/en-my').'/">', false);
        $res->assertSee('<meta property="og:url" content="'.url('/en-my').'/">', false);

        // Sitemap page URLs are slashed; image URLs (file paths) are left alone.
        $map = $this->get('/sitemap.xml')->assertOk();
        $map->assertSee('<loc>'.url('/en-my').'/</loc>', false);
        $map->assertSee('<loc>'.url('/en-my/e/'.$event->slug).'/</loc>', false);
        $map->assertSee('<image:loc>'.htmlspecialchars($event->cover_image, ENT_XML1).'</image:loc>', false);
    }

    public function test_event_page_has_full_server_rendered_seo(): void
    {
        $event = $this->publishedEvent();

        $res = $this->get('/e/'.$event->slug)->assertOk();
        $res->assertSee('<title>Neon Nights', false);
        $res->assertSee('<meta name="description" content="Four acts under the stars."', false);
        $res->assertSee('<link rel="canonical" href="'.url('/en-my/e/'.$event->slug).'/">', false);
        $res->assertSee('max-image-preview:large', false);                    // indexable, rich
        $res->assertSee('<meta property="og:image" content="https://img.test/cover.jpg">', false);
        $res->assertSee('"@type":"Event"', false);                            // Event schema
        $res->assertSee('"@type":"BreadcrumbList"', false);                   // breadcrumbs
        $res->assertSee('"@type":"Offer"', false);                            // ticket offer
    }

    public function test_draft_event_preview_is_noindex(): void
    {
        $host = User::factory()->create();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Secret', 'slug' => 'secret-draft',
            'status' => 'draft', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        $this->actingAs($host)->get('/e/'.$event->slug)
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_blog_post_has_article_structured_data(): void
    {
        $author = User::factory()->create();
        $post = CmsPost::create([
            'author_id' => $author->id, 'title' => 'How to host', 'slug' => 'how-to-host',
            'excerpt' => 'A quick guide.', 'body' => '<p>Body copy here with several words.</p>',
            'status' => 'published', 'published_at' => now(),
        ]);

        $res = $this->get('/blog/'.$post->slug)->assertOk();
        $res->assertSee('"@type":"BlogPosting"', false);
        $res->assertSee('<meta property="og:type" content="article">', false);
        $res->assertSee('article:published_time', false);
        $res->assertSee('"wordCount"', false);
    }

    public function test_cms_page_has_webpage_structured_data_and_honors_robots(): void
    {
        $page = CmsPage::create(['title' => 'About', 'slug' => 'about', 'body' => '<p>Hi</p>', 'status' => 'published', 'published_at' => now()]);
        $page->seo()->create(['meta_description' => 'About us', 'robots_index' => false, 'robots_follow' => true]);

        $res = $this->get('/about')->assertOk();
        $res->assertSee('"@type":"WebPage"', false);
        $res->assertSee('<meta name="description" content="About us">', false);
        $res->assertSee('<meta name="robots" content="noindex, follow">', false); // honors SeoMeta
    }

    public function test_ticket_pass_is_noindex(): void
    {
        $event = $this->publishedEvent(['slug' => 'ticket-ev']);
        $order = $event->orders()->create(['reference' => 'DRSVP-TIX01', 'status' => 'paid', 'total' => 0, 'currency' => 'MYR']);
        $ticket = Ticket::create(['order_id' => $order->id, 'event_id' => $event->id, 'status' => 'valid']);

        $this->get('/tickets/'.$ticket->qr_token)
            ->assertOk()
            ->assertSee('noindex', false);
    }
}
