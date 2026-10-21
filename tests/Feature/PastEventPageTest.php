<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventPhoto;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An SEO review of the live site: the only event page had already happened,
 * showed no price, and its JSON-LD had no offers and called it free.
 *
 * Past events keep their page, say they are over, stop selling, state what
 * the tickets cost, and point at what is on next.
 */
class PastEventPageTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private EventCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 12:00:00');
        $this->host = User::factory()->create(['name' => 'BoardLah Entertainment', 'slug' => 'boardlah-entertainment']);
        $this->category = EventCategory::create(['name' => 'Community', 'slug' => 'community']);
    }

    private function event(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'user_id' => $this->host->id, 'category_id' => $this->category->id,
            'title' => 'Blood on the Clocktower Session', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subMonth(),
            'timezone' => 'Asia/Kuala_Lumpur', 'venue_name' => 'HOL Cafe', 'city' => 'Kajang',
            // 15:00–22:30 in Kuala Lumpur on 10 Oct: well over by the 21st.
            'starts_at' => '2026-10-10 07:00:00', 'ends_at' => '2026-10-10 14:30:00',
        ], $attributes));
    }

    private function type(Event $event, array $attributes = []): TicketType
    {
        return TicketType::create(array_merge([
            'event_id' => $event->id, 'name' => 'Session A', 'kind' => 'paid', 'price' => 25,
            'currency' => 'MYR', 'quantity' => 30, 'is_active' => true,
        ], $attributes));
    }

    private function eventNode(string $html): array
    {
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);
        $graph = json_decode(str_replace('<\/', '</', $m[1] ?? ''), true);
        $this->assertIsArray($graph, 'the JSON-LD did not decode');

        return collect($graph['@graph'])->firstWhere('@type', 'Event') ?? [];
    }

    // ---- the page --------------------------------------------------------------

    public function test_a_past_event_keeps_its_page_and_says_it_has_ended(): void
    {
        $event = $this->event();
        $this->type($event);

        $this->get('/en-my/e/clocktower/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/event')
                ->where('event.has_ended', true)
                ->where('event.ticket_types.0.on_sale', false));
    }

    public function test_an_upcoming_event_is_not_marked_as_ended(): void
    {
        $event = $this->event(['slug' => 'next', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
        $this->type($event);

        $this->get('/en-my/e/next/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('event.has_ended', false)
                ->where('event.ticket_types.0.on_sale', true));
    }

    public function test_the_price_is_on_the_page_even_after_the_tickets_were_switched_off(): void
    {
        // What production had: a past event whose ticket types were deactivated,
        // so neither the page nor the JSON-LD said anything about price.
        $event = $this->event();
        $this->type($event, ['name' => 'Session A', 'price' => 25, 'is_active' => false]);
        $this->type($event, ['name' => 'Session B', 'price' => 35, 'is_active' => false]);

        $this->get('/en-my/e/clocktower/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('event.price_label', 'RM 25.00 – RM 35.00')
                ->where('event.price_list.0', ['name' => 'Session A', 'price' => 'RM 25.00'])
                ->where('event.price_list.1', ['name' => 'Session B', 'price' => 'RM 35.00']));
    }

    public function test_price_labels_read_naturally(): void
    {
        $free = $this->event(['slug' => 'free', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
        $this->type($free, ['kind' => 'free', 'price' => 0]);

        $mixed = $this->event(['slug' => 'mixed', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
        $this->type($mixed, ['kind' => 'free', 'price' => 0, 'name' => 'Standing']);
        $this->type($mixed, ['price' => 80, 'name' => 'VIP']);

        $one = $this->event(['slug' => 'one', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
        $this->type($one, ['price' => 25]);

        $this->get('/en-my/e/free/')->assertInertia(fn (Assert $p) => $p->where('event.price_label', 'Free'));
        $this->get('/en-my/e/mixed/')->assertInertia(fn (Assert $p) => $p->where('event.price_label', 'Free – RM 80.00'));
        $this->get('/en-my/e/one/')->assertInertia(fn (Assert $p) => $p->where('event.price_label', 'RM 25.00'));
    }

    public function test_tickets_for_a_past_event_cannot_be_bought(): void
    {
        $event = $this->event();
        $type = $this->type($event);

        $this->from('/en-my/e/clocktower/')
            ->post(route('checkout.start', $event), ['items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]])
            ->assertRedirect('/en-my/e/clocktower/')
            ->assertSessionHas('flash_error');

        $this->assertSame(0, Order::count());
    }

    public function test_it_points_at_upcoming_events_from_the_organizer_then_the_category(): void
    {
        $past = $this->event();
        $this->type($past);

        $mine = $this->event(['slug' => 'mine-next', 'title' => 'Board Game Night', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
        $this->type($mine);

        $other = User::factory()->create();
        $theirs = $this->event(['user_id' => $other->id, 'slug' => 'theirs', 'title' => 'Werewolf Night', 'starts_at' => now()->addDays(10), 'ends_at' => null]);
        $this->type($theirs);

        // Neither another past event nor an unrelated category is suggested.
        $this->event(['slug' => 'old', 'starts_at' => now()->subMonths(2), 'ends_at' => null]);
        $music = EventCategory::create(['name' => 'Music', 'slug' => 'music']);
        $this->event(['user_id' => $other->id, 'category_id' => $music->id, 'slug' => 'gig', 'starts_at' => now()->addDays(3), 'ends_at' => null]);

        $response = $this->get('/en-my/e/clocktower/')->assertOk();

        $response->assertInertia(fn (Assert $p) => $p
            ->has('related', 2)
            ->where('related.0.slug', 'mine-next')
            ->where('related.0.same_organizer', true)
            ->where('related.0.price', 'RM 25.00')
            ->where('related.1.slug', 'theirs')
            ->where('related.1.same_organizer', false));

        // And in the text a crawler reads.
        $response->assertSee('This event has ended.', false)
            ->assertSee('Board Game Night')
            ->assertDontSee('Get tickets for Blood on the Clocktower Session', false);
    }

    public function test_photos_from_the_day_lead_the_gallery(): void
    {
        $event = $this->event(['gallery' => ['/storage/promo.jpg']]);
        EventPhoto::create(['event_id' => $event->id, 'uploaded_by' => $this->host->id, 'path' => '/storage/on-the-night.jpg']);

        $this->get('/en-my/e/clocktower/')->assertInertia(fn (Assert $p) => $p
            ->where('event.gallery.0', url('/storage/on-the-night.jpg'))
            ->where('event.gallery.1', url('/storage/promo.jpg')));
    }

    // ---- the structured data -----------------------------------------------------

    public function test_a_past_events_json_ld_states_its_prices_without_claiming_stock(): void
    {
        $event = $this->event();
        $this->type($event, ['name' => 'Session A', 'price' => 25, 'is_active' => false]);

        $node = $this->eventNode($this->get('/en-my/e/clocktower/')->getContent());

        $this->assertSame('https://schema.org/EventScheduled', $node['eventStatus']);
        $this->assertSame('https://schema.org/OfflineEventAttendanceMode', $node['eventAttendanceMode']);
        $this->assertSame('Place', $node['location']['@type']);
        $this->assertSame('PostalAddress', $node['location']['address']['@type']);
        $this->assertSame('BoardLah Entertainment', $node['organizer']['name']);
        // A paid event is not free, whatever state its tickets are in.
        $this->assertFalse($node['isAccessibleForFree']);

        $offer = collect($node['offers'])->firstWhere('@type', 'Offer');
        $this->assertSame('25.00', $offer['price']);
        $this->assertSame('MYR', $offer['priceCurrency']);
        $this->assertArrayNotHasKey('availability', $offer);
        $this->assertArrayNotHasKey('availability', collect($node['offers'])->firstWhere('@type', 'AggregateOffer'));
    }

    public function test_a_free_event_still_states_a_price_of_zero_in_myr(): void
    {
        $event = $this->event(['slug' => 'free', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
        $this->type($event, ['kind' => 'free', 'price' => 0, 'name' => 'RSVP']);

        $node = $this->eventNode($this->get('/en-my/e/free/')->getContent());

        $this->assertTrue($node['isAccessibleForFree']);
        $this->assertSame('0.00', $node['offers'][0]['price']);
        $this->assertSame('MYR', $node['offers'][0]['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $node['offers'][0]['availability']);
    }

    public function test_an_event_with_no_tickets_at_all_is_not_called_free(): void
    {
        $this->event(['slug' => 'bare', 'starts_at' => now()->addWeek(), 'ends_at' => null]);

        $node = $this->eventNode($this->get('/en-my/e/bare/')->getContent());

        $this->assertArrayNotHasKey('isAccessibleForFree', $node);
        $this->assertArrayNotHasKey('offers', $node);
    }

    public function test_a_bad_character_in_the_description_does_not_blank_the_json_ld(): void
    {
        $event = $this->event(['slug' => 'broken', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
        // Bypass the model: the kind of byte an old import could leave behind.
        Event::whereKey($event->id)->update(['description' => "Fun night \xB1\xFF out"]);

        $node = $this->eventNode($this->get('/en-my/e/broken/')->getContent());

        $this->assertSame('Blood on the Clocktower Session', $node['name']);
    }

    // ---- what the live <head> shows after moving around the app ---------------------

    public function test_an_in_app_visit_brings_the_pages_own_head_tags(): void
    {
        $event = $this->event(['slug' => 'next', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
        $this->type($event);

        $version = app(HandleInertiaRequests::class)->version(request());

        $props = $this->get('/en-my/e/next/', array_filter(['X-Inertia' => 'true', 'X-Inertia-Version' => $version]))
            ->assertOk()
            ->json('props');

        $this->assertStringContainsString('"@type":"Event"', $props['seoHead']);
        $this->assertStringContainsString('<link rel="canonical"', $props['seoHead']);
        // The title is Inertia's to manage, so it is not in the block.
        $this->assertStringNotContainsString('<title>', $props['seoHead']);
    }

    public function test_a_full_load_marks_the_block_and_does_not_send_it_twice(): void
    {
        $event = $this->event(['slug' => 'next', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
        $this->type($event);

        $this->get('/en-my/e/next/')->assertOk()
            ->assertSee('<!--seo-head-->', false)
            ->assertSee('<!--/seo-head-->', false)
            ->assertInertia(fn (Assert $p) => $p->where('seoHead', null));
    }
}
