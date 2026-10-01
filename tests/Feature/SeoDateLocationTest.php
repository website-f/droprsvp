<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\OrganizerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Event pages spell out WHEN and WHERE beyond the title: keywords, geo tags and
 * start/end meta — and the admin SEO editor previews what the page really emits.
 */
class SeoDateLocationTest extends TestCase
{
    use RefreshDatabase;

    private function event(): Event
    {
        $host = $this->organizer(['name' => 'Sophia Kuek', 'slug' => 'boardlah']);
        OrganizerProfile::create(['user_id' => $host->id, 'business_name' => 'BoardLah Entertainment', 'status' => 'approved']);
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        return Event::create([
            'user_id' => $host->id, 'category_id' => $category->id,
            'title' => 'Blood on the Clocktower Session', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'city' => 'Kajang', 'venue_name' => 'HOL Cafe',
            'latitude' => 2.9935, 'longitude' => 101.7874,
            'starts_at' => Carbon::parse('2026-10-10 15:00', 'Asia/Kuala_Lumpur')->utc(),
            'ends_at' => Carbon::parse('2026-10-10 18:00', 'Asia/Kuala_Lumpur')->utc(),
            'published_at' => now(),
        ]);
    }

    public function test_event_page_carries_date_and_location_meta(): void
    {
        $this->event();

        $html = $this->get('/en-my/e/clocktower')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Blood on the Clocktower Session – Kajang, 10 Oct 2026 | DropRSVP</title>', $html);
        $this->assertMatchesRegularExpression('/<meta name="keywords" content="[^"]*events in Kajang[^"]*events in Selangor October 2026[^"]*10 Oct 2026[^"]*HOL Cafe/', $html);
        $this->assertStringContainsString('<meta name="geo.region" content="MY-10">', $html);
        $this->assertStringContainsString('<meta name="geo.placename" content="Kajang, Selangor, Malaysia">', $html);
        $this->assertStringContainsString('<meta name="geo.position" content="2.9935;101.7874">', $html);
        $this->assertStringContainsString('<meta property="event:start_time" content="2026-10-10T15:00:00+08:00">', $html);
        // The JSON-LD organizer is the trading name, like the title and description.
        $this->assertStringContainsString('"organizer":{"@type":"Organization","name":"BoardLah Entertainment"', $html);
        $this->assertStringNotContainsString('Sophia Kuek', $html);
    }

    public function test_organizer_page_has_keywords(): void
    {
        $this->event();

        $this->get('/en-my/o/boardlah')->assertOk()
            ->assertSee('<meta name="keywords" content="BoardLah Entertainment, BoardLah Entertainment events, event organiser', false);
    }

    public function test_admin_seo_editor_previews_the_house_template(): void
    {
        $this->event();
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get('/admin/seo/events/clocktower')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fallback.title', 'Blood on the Clocktower Session – Kajang, 10 Oct 2026 | DropRSVP')
                ->where('fallback.description', 'Community event by BoardLah Entertainment at HOL Cafe, Kajang on Sat, 10 Oct 2026, 3pm. Book now on DropRSVP platform.'));
    }
}
