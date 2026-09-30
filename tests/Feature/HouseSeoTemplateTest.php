<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\OrganizerProfile;
use App\Models\User;
use App\Support\SeoTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HOUSE templates — the ones every event and organizer page gets without
 * anybody editing anything.
 *
 * SeoTemplateTest already covers token substitution and a per-event override.
 * What was missing, and what was reported as "the template doesn't take
 * effect", is the default path: an event nobody has touched in the SEO manager
 * should still come out as
 *
 *     Blood on the Clocktower Session – Kajang, 10 Oct 2026 | DropRSVP
 *
 * rather than its bare title. These assert the exact shipped strings, so a
 * change to DEFAULTS that breaks the agreed house style fails here.
 */
class HouseSeoTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function host(string $name = 'BoardLah Entertainment'): User
    {
        $user = $this->organizer(['name' => $name, 'slug' => 'boardlah-entertainment']);

        OrganizerProfile::create([
            'user_id' => $user->id,
            'business_name' => $name,
            'status' => 'approved',
        ]);

        return $user;
    }

    private function event(User $host): Event
    {
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        return Event::create([
            'user_id' => $host->id,
            'category_id' => $category->id,
            'title' => 'Blood on the Clocktower Session',
            'slug' => 'blood-on-the-clocktower-session',
            'description' => 'A social-deduction game night.',
            'status' => 'published',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'city' => 'Kajang',
            'venue_name' => 'HOL Cafe',
            // 3pm Kuala Lumpur time, stored as UTC like the app does.
            'starts_at' => '2026-10-10 07:00:00',
            'published_at' => now(),
        ]);
    }

    public function test_the_shipped_defaults_are_the_agreed_house_style(): void
    {
        $this->assertSame('{event_name} – {city}, {short_date} | {site}', SeoTemplate::DEFAULTS['event_title']);
        $this->assertSame(
            '{category} event by {organizer} at {venue}, {city} on {day_date}, {start_time}. Book now on {site} platform.',
            SeoTemplate::DEFAULTS['event_description'],
        );
        $this->assertSame('{organizer} · Event Organiser | {site}', SeoTemplate::DEFAULTS['organizer_title']);
        $this->assertSame(
            'Follow {organizer} on {site} platform to discover their latest events and book your spot.',
            SeoTemplate::DEFAULTS['organizer_description'],
        );
    }

    public function test_an_untouched_event_page_uses_the_house_template(): void
    {
        config(['seo.site_name' => 'DropRSVP', 'app.name' => 'DropRSVP']);
        $event = $this->event($this->host());

        $response = $this->get('/en-my/e/'.$event->slug)->assertOk();

        $response->assertSee('<title>Blood on the Clocktower Session – Kajang, 10 Oct 2026 | DropRSVP</title>', false);
        $response->assertSee(
            'Community event by BoardLah Entertainment at HOL Cafe, Kajang on Sat, 10 Oct 2026, 3pm. Book now on DropRSVP platform.',
            false,
        );
    }

    public function test_an_untouched_organizer_page_uses_the_house_template(): void
    {
        config(['seo.site_name' => 'DropRSVP', 'app.name' => 'DropRSVP']);
        $host = $this->host();
        $this->event($host);

        $response = $this->get('/en-my/o/'.$host->slug)->assertOk();

        $response->assertSee('<title>BoardLah Entertainment · Event Organiser | DropRSVP</title>', false);
        $response->assertSee(
            'Follow BoardLah Entertainment on DropRSVP platform to discover their latest events and book your spot.',
            false,
        );
    }

    public function test_the_event_canonical_points_at_the_event_not_the_homepage(): void
    {
        $event = $this->event($this->host());

        // Reported from production as "canonical says /en-my/ on an event page".
        // Whatever caused that, it must not be this code path.
        $this->get('/en-my/e/'.$event->slug)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/en-my/e/'.$event->slug).'/">', false);
    }

    public function test_a_per_event_override_still_wins_over_the_house_template(): void
    {
        $event = $this->event($this->host());
        $event->seo()->create(['seo_title' => 'Game night in {city}']);

        $this->get('/en-my/e/'.$event->slug)
            ->assertOk()
            ->assertSee('Game night in Kajang', false);
    }
}
