<?php

namespace Tests\Feature;

use App\Models\CmsPost;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Setting;
use App\Models\TicketType;
use App\Models\User;
use App\Support\SeoTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The house SEO templates, the sitemap index, and the 404 page.
 *
 * Every event page used to title itself with nothing but the event's own name,
 * which across hundreds of events gives a results page full of entries you
 * cannot tell apart. One template per page type fixes that, provided the tokens
 * actually resolve — including on the awkward events (online, undated) where a
 * naive substitution leaves ", ," behind.
 */
class SeoTemplateAndSitemapTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $attributes = []): Event
    {
        $host = User::factory()->create(['name' => 'BoardLah Entertainment', 'slug' => 'boardlah-entertainment']);
        $category = EventCategory::create(['name' => 'Community', 'slug' => 'community-'.uniqid()]);

        return Event::create(array_merge([
            'user_id' => $host->id,
            'category_id' => $category->id,
            'title' => 'Blood on the Clocktower Session',
            'slug' => 'clocktower-'.uniqid(),
            'status' => 'published',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'venue_name' => 'HOL Cafe',
            'city' => 'Kajang',
            // 15:00 in Kuala Lumpur is 07:00 UTC. Stored in UTC, as everywhere.
            'starts_at' => '2026-10-10 07:00:00',
            'published_at' => now()->subDay(),
        ], $attributes));
    }

    public function test_an_event_title_follows_the_house_template(): void
    {
        $event = $this->event();

        $this->get("/en-my/e/{$event->slug}/")
            ->assertOk()
            ->assertSee('<title>Blood on the Clocktower Session – Kajang, 10 Oct 2026 | DropRSVP</title>', false);
    }

    public function test_an_event_description_follows_the_house_template(): void
    {
        $event = $this->event();

        $this->get("/en-my/e/{$event->slug}/")
            ->assertOk()
            ->assertSee(
                'Community event by BoardLah Entertainment at HOL Cafe, Kajang on Sat, 10 Oct 2026, 3pm. Book now on DropRSVP platform.',
                false,
            );
    }

    public function test_the_start_time_is_in_the_events_timezone_not_the_servers(): void
    {
        // The same class of bug as the "3pm showing as 11pm" report: formatting
        // a UTC timestamp without converting to the event's zone first.
        $event = $this->event();

        $this->assertSame('3pm', SeoTemplate::values($event)['{start_time}']);
        $this->assertSame('Sat, 10 Oct 2026', SeoTemplate::values($event)['{day_date}']);
    }

    public function test_a_half_past_start_reads_as_a_malaysian_would_write_it(): void
    {
        // 11:30 in Kuala Lumpur.
        $event = $this->event(['starts_at' => '2026-10-10 03:30:00']);

        $this->assertSame('11.30am', SeoTemplate::values($event)['{start_time}']);
    }

    public function test_an_online_event_does_not_leave_a_dangling_comma(): void
    {
        // No venue and no city: a naive substitution gives "at , on Sat, …".
        $event = $this->event(['is_online' => true, 'venue_name' => null, 'city' => null]);

        $rendered = SeoTemplate::forEvent('event_description', $event);

        $this->assertStringNotContainsString(', ,', $rendered);
        $this->assertStringNotContainsString(' ,', $rendered);
        $this->assertStringContainsString('Online', $rendered);
        $this->assertStringContainsString('Book now on DropRSVP platform.', $rendered);
    }

    public function test_an_undated_event_does_not_produce_a_stranded_preposition(): void
    {
        $event = $this->event(['starts_at' => null]);

        $rendered = SeoTemplate::forEvent('event_description', $event);

        $this->assertStringNotContainsString('on ,', $rendered);
        $this->assertStringNotContainsString(', .', $rendered);
        $this->assertStringEndsWith('Book now on DropRSVP platform.', $rendered);
    }

    public function test_an_uncategorised_event_still_opens_with_a_capital(): void
    {
        // The template opens with {category}. Dropping it leaves "event by …",
        // which reads as a typo in a search result.
        $event = $this->event(['category_id' => null]);

        $this->assertStringStartsWith('Event by BoardLah Entertainment', SeoTemplate::forEvent('event_description', $event));
    }

    public function test_a_value_that_is_not_a_sentence_is_not_capitalised(): void
    {
        // The same render path handles per-event overrides, including the
        // keywords list — which must come back exactly as it was typed.
        $event = $this->event();

        $this->assertSame('neon, rooftop, live music', SeoTemplate::render('neon, rooftop, live music', $event));
    }

    public function test_an_events_own_override_still_wins_over_the_template(): void
    {
        $event = $this->event();
        $event->seo()->create(['seo_title' => 'Hand written title for {city}']);

        $this->get("/en-my/e/{$event->slug}/")
            ->assertOk()
            ->assertSee('Hand written title for Kajang', false);
    }

    public function test_an_organizer_page_follows_its_own_template(): void
    {
        $this->event();

        $this->get('/en-my/o/boardlah-entertainment/')
            ->assertOk()
            ->assertSee('<title>BoardLah Entertainment · Event Organiser | DropRSVP</title>', false)
            ->assertSee('Follow BoardLah Entertainment on DropRSVP platform to discover their latest events and book your spot.', false);
    }

    public function test_a_superadmin_can_change_the_house_template(): void
    {
        $event = $this->event();

        Setting::putArray('seo_templates', ['event_title' => 'Tickets for {event_name} in {city}']);

        $this->get("/en-my/e/{$event->slug}/")
            ->assertOk()
            ->assertSee('Tickets for Blood on the Clocktower Session in Kajang', false);
    }

    public function test_clearing_a_template_falls_back_to_the_shipped_default(): void
    {
        // Blank must mean "use the default", never "emit no title at all".
        Setting::putArray('seo_templates', ['event_title' => '   ']);

        $this->assertSame(SeoTemplate::DEFAULTS['event_title'], SeoTemplate::house('event_title'));
    }

    // ---- schema ------------------------------------------------------------

    public function test_the_event_schema_carries_price_availability_and_a_real_address(): void
    {
        $event = $this->event();
        TicketType::create([
            'event_id' => $event->id, 'name' => 'General', 'kind' => 'paid',
            'price' => 25, 'currency' => 'MYR', 'quantity' => 50, 'is_active' => true,
        ]);

        $html = $this->get("/en-my/e/{$event->slug}/")->assertOk()->getContent();
        $json = $this->jsonLd($html);
        $event_ = collect($json['@graph'])->firstWhere('@type', 'Event');

        $this->assertNotNull($event_, 'No Event node in the JSON-LD graph.');

        // The address as separate fields, not one free-text line.
        $this->assertSame('Kajang', $event_['location']['address']['addressLocality']);
        $this->assertSame('Selangor', $event_['location']['address']['addressRegion']);
        $this->assertSame('MY', $event_['location']['address']['addressCountry']);
        $this->assertSame('HOL Cafe', $event_['location']['name']);

        // An aggregate offer in front, so a result can say "from RM25".
        $this->assertSame('AggregateOffer', $event_['offers'][0]['@type']);
        $this->assertSame('25.00', $event_['offers'][0]['lowPrice']);
        $this->assertSame('MYR', $event_['offers'][0]['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $event_['offers'][0]['availability']);

        // …and the individual ticket type behind it, with a validity window.
        $this->assertSame('Offer', $event_['offers'][1]['@type']);
        $this->assertSame('General', $event_['offers'][1]['name']);
        $this->assertArrayHasKey('validThrough', $event_['offers'][1]);

        $this->assertSame('https://schema.org/EventScheduled', $event_['eventStatus']);
        $this->assertSame('Community', $event_['keywords']);
    }

    public function test_a_sold_out_event_says_so_in_its_schema(): void
    {
        $event = $this->event();
        TicketType::create([
            'event_id' => $event->id, 'name' => 'General', 'kind' => 'paid',
            'price' => 25, 'currency' => 'MYR', 'quantity' => 0, 'is_active' => true,
        ]);

        $json = $this->jsonLd($this->get("/en-my/e/{$event->slug}/")->getContent());
        $node = collect($json['@graph'])->firstWhere('@type', 'Event');

        $this->assertSame('https://schema.org/SoldOut', $node['offers'][0]['availability']);
    }

    public function test_a_free_event_is_marked_as_free(): void
    {
        $event = $this->event();
        TicketType::create([
            'event_id' => $event->id, 'name' => 'RSVP', 'kind' => 'free',
            'price' => 0, 'currency' => 'MYR', 'quantity' => 30, 'is_active' => true,
        ]);

        $json = $this->jsonLd($this->get("/en-my/e/{$event->slug}/")->getContent());
        $node = collect($json['@graph'])->firstWhere('@type', 'Event');

        $this->assertTrue($node['isAccessibleForFree']);
    }

    public function test_the_organizer_page_describes_itself_and_lists_its_events(): void
    {
        $this->event();

        $json = $this->jsonLd($this->get('/en-my/o/boardlah-entertainment/')->getContent());
        $types = collect($json['@graph'])->pluck('@type');

        $this->assertTrue($types->contains('ProfilePage'));
        $this->assertTrue($types->contains('ItemList'));

        $list = collect($json['@graph'])->firstWhere('@type', 'ItemList');
        $this->assertSame(1, $list['numberOfItems']);
        $this->assertStringContainsString('/e/', $list['itemListElement'][0]['url']);
    }

    // ---- sitemap -----------------------------------------------------------

    public function test_the_sitemap_is_an_index_of_per_type_sitemaps(): void
    {
        $this->event();

        $xml = $this->get('/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->getContent();

        $this->assertStringContainsString('<sitemapindex', $xml);
        $this->assertStringContainsString('/event-sitemap.xml', $xml);
        $this->assertStringContainsString('/organizer-sitemap.xml', $xml);
        $this->assertStringContainsString('/page-sitemap.xml', $xml);
    }

    public function test_a_newly_created_event_appears_in_the_event_sitemap(): void
    {
        $event = $this->event();

        $this->get('/event-sitemap.xml')->assertOk()
            ->assertSee("/e/{$event->slug}/", false);
    }

    public function test_a_newly_created_organizer_appears_in_the_organizer_sitemap(): void
    {
        $this->event();

        $this->get('/organizer-sitemap.xml')->assertOk()
            ->assertSee('/o/boardlah-entertainment/', false);
    }

    public function test_an_organizer_with_only_drafts_is_not_submitted(): void
    {
        // Their profile page has nothing on it, and submitting empty pages is
        // how a site collects "crawled — currently not indexed" in Search Console.
        $this->event(['status' => 'draft']);

        $this->get('/organizer-sitemap.xml')->assertOk()
            ->assertDontSee('/o/boardlah-entertainment/', false);
    }

    public function test_a_newly_published_post_appears_in_the_post_sitemap(): void
    {
        CmsPost::create([
            'title' => 'Hello', 'slug' => 'hello-there', 'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        $this->get('/post-sitemap.xml')->assertOk()->assertSee('/blog/hello-there/', false);
    }

    public function test_an_unknown_section_is_a_404_not_an_empty_sitemap(): void
    {
        $this->get('/nonsense-sitemap.xml')->assertNotFound();
    }

    public function test_the_yoast_style_index_url_also_works(): void
    {
        // Anyone submitting by WordPress habit should land somewhere real.
        $this->get('/sitemap_index.xml')->assertOk()->assertSee('<sitemapindex', false);
    }

    // ---- 404 ---------------------------------------------------------------

    public function test_the_404_page_is_ours_and_offers_a_way_out(): void
    {
        $response = $this->get('/en-my/e/no-such-event-anywhere/');

        $response->assertNotFound()
            ->assertSee('Sorry, this event has left the building.', false)
            ->assertSee('Browse all events', false)
            // noindex so it never ranks, follow so the links out still count.
            ->assertSee('content="noindex, follow"', false);
    }

    public function test_the_404_page_does_not_reflect_markup_from_the_url(): void
    {
        // It prints the requested path back, which is attacker-controlled.
        $this->get('/en-my/e/'.urlencode('<script>alert(1)</script>').'/')
            ->assertNotFound()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    /** @return array<string,mixed> */
    private function jsonLd(string $html): array
    {
        $this->assertMatchesRegularExpression('/<script type="application\/ld\+json">/', $html);
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);

        $decoded = json_decode(str_replace('<\/', '</', $m[1]), true);
        $this->assertIsArray($decoded, 'JSON-LD did not parse.');
        $this->assertSame('https://schema.org', $decoded['@context'] ?? null);

        return $decoded;
    }
}
