<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Body CONTENT in the server response, not just meta tags.
 *
 * There is no Node/SSR in production, so a crawler or agent that doesn't run
 * JavaScript sees only what Laravel writes into the HTML. Rich meta tags were
 * already there, but the main pages had no body text at all — fetching the home
 * page returned a title, some Open Graph tags and an empty shell, which is what
 * "the site can't be crawled" actually meant.
 *
 * Every assertion reads the raw response body, exactly what such a client gets.
 * The content mirrors what React renders for real visitors — same events, same
 * facts — so this is one page in two formats, not a crawler-only variant.
 *
 * @see \App\Support\SeoManager::crawlable()
 */
class CrawlableContentTest extends TestCase
{
    use RefreshDatabase;

    private function publishedEvent(array $overrides = []): Event
    {
        $host = User::factory()->create(['slug' => 'neon-collective', 'name' => 'Neon Collective']);
        $category = EventCategory::firstOrCreate(
            ['slug' => 'music'],
            ['name' => 'Music', 'sort_order' => 1],
        );

        $event = Event::create(array_merge([
            'user_id' => $host->id,
            'category_id' => $category->id,
            'title' => 'Neon Nights',
            'slug' => 'neon-nights',
            'subtitle' => 'A rooftop live night',
            'description' => 'Four acts under the stars.',
            'status' => 'published',
            'visibility' => 'public',
            'city' => 'Kuala Lumpur',
            'venue_name' => 'Skydeck KL',
            'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(10),
        ], $overrides));

        TicketType::create([
            'event_id' => $event->id, 'name' => 'GA', 'kind' => 'paid',
            'price' => 65, 'currency' => 'MYR', 'quantity' => 100, 'is_active' => true,
        ]);

        return $event;
    }

    public function test_the_home_page_serves_its_featured_events_as_text(): void
    {
        $this->publishedEvent();

        $response = $this->get('/en-my/')->assertOk();

        // The event itself, not just a link to a JS bundle that would fetch it.
        $response->assertSee('Neon Nights', false);
        $response->assertSee('Featured events', false);

        // A crawlable path deeper into the site.
        $response->assertSee(\App\Support\Url::path('e', 'neon-nights'), false);
        $response->assertSee(\App\Support\Url::path('all'), false);
    }

    public function test_the_discover_listing_serves_its_events_as_text(): void
    {
        $this->publishedEvent();

        $response = $this->get('/en-my/all/')->assertOk();

        $response->assertSee('Neon Nights', false);
        $response->assertSee('Skydeck KL', false);
        $response->assertSee(\App\Support\Url::path('e', 'neon-nights'), false);
    }

    public function test_a_city_page_serves_only_that_city_and_says_so_when_empty(): void
    {
        $this->publishedEvent();

        $this->get('/en-my/kuala-lumpur/')->assertOk()->assertSee('Neon Nights', false);

        // A city with nothing on must still say something, not render blank.
        $this->get('/en-my/ipoh/')->assertOk()->assertSee('No events listed here yet.', false);
    }

    public function test_an_event_page_serves_when_where_and_who_as_text(): void
    {
        $this->publishedEvent();

        $response = $this->get('/en-my/e/neon-nights/')->assertOk();

        $response->assertSee('Neon Nights', false);
        $response->assertSee('A rooftop live night', false);
        $response->assertSee('Four acts under the stars.', false);
        $response->assertSee('Skydeck KL', false);
        $response->assertSee('Neon Collective', false);

        // Links out to the organizer profile — another crawlable path.
        $response->assertSee(\App\Support\Url::path('o', 'neon-collective'), false);
    }

    public function test_an_organizer_profile_serves_its_events_as_text(): void
    {
        $this->publishedEvent();

        $response = $this->get('/en-my/o/neon-collective/')->assertOk();

        $response->assertSee('Neon Collective', false);
        $response->assertSee('Upcoming events', false);
        $response->assertSee('Neon Nights', false);
    }

    /**
     * The organizer canonical used to be url("/o/{slug}") — the legacy path,
     * which 301s to the locale form. A canonical pointing at a URL that
     * redirects elsewhere names no valid canonical at all.
     */
    public function test_the_organizer_canonical_is_the_locale_trailing_slash_url(): void
    {
        $this->publishedEvent();

        $expected = \App\Support\Url::to('o', 'neon-collective');

        $response = $this->get('/en-my/o/neon-collective/')->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$expected.'">', false);
        $response->assertSee('<meta property="og:url" content="'.$expected.'">', false);

        // Specifically NOT the legacy, locale-less path.
        $response->assertDontSee('<link rel="canonical" href="'.url('/o/neon-collective').'">', false);
    }

    public function test_a_draft_event_serves_no_crawlable_content(): void
    {
        $this->publishedEvent(['status' => 'draft', 'slug' => 'secret-gig', 'title' => 'Secret Gig']);

        // Owner-preview / draft pages are noindexed; they must not hand a
        // crawler the content either.
        $this->get('/en-my/e/secret-gig/')->assertNotFound();
    }
}
