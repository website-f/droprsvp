<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\OrganizerProfile;
use App\Models\User;
use App\Support\Tracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The two halves of keeping analytics off the back office, and the title the
 * browser ends up reporting.
 *
 * Reported after the first fix shipped: "dashboard - DropRSVP" was still
 * showing in the GA property. The server had stopped rendering the tag there,
 * but this is a SPA — a visitor who arrives on a public page keeps the tag
 * loaded and GA counts every later history change as a page view, panel
 * screens included. The browser has to be handed the rule too.
 */
class TrackingScopeTest extends TestCase
{
    use RefreshDatabase;

    private function publicEvent(): Event
    {
        $host = $this->organizer(['name' => 'BoardLah Entertainment', 'slug' => 'boardlah']);
        OrganizerProfile::create(['user_id' => $host->id, 'business_name' => 'BoardLah Entertainment', 'status' => 'approved']);
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        return Event::create([
            'user_id' => $host->id, 'category_id' => $category->id,
            'title' => 'Blood on the Clocktower Session', 'slug' => 'botc',
            'description' => 'Social deduction.', 'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'city' => 'Kajang', 'venue_name' => 'HOL Cafe',
            'starts_at' => '2026-10-10 07:00:00', 'published_at' => now(),
        ]);
    }

    // ---- the pattern the browser is given ----------------------------------

    public function test_the_client_pattern_covers_every_private_path(): void
    {
        $pattern = '/'.Tracking::privatePathPattern().'/';

        $private = [
            '/admin', '/admin/', '/admin/overview', '/admin/users/3', '/admin/cms/pages',
            '/host', '/host/events', '/host/events/botc/attendees',
            '/dashboard', '/dashboard/',
            '/settings', '/settings/profile',
            '/my/tickets', '/my/orders/5/receipt',
            '/notifications', '/following', '/premium',
            '/login', '/register', '/set-password', '/auth/google/callback',
        ];

        foreach ($private as $path) {
            $this->assertMatchesRegularExpression($pattern, $path, "{$path} is the back office and must be muted.");
        }
    }

    public function test_the_client_pattern_leaves_public_paths_alone(): void
    {
        $pattern = '/'.Tracking::privatePathPattern().'/';

        $public = [
            '/', '/en-my/', '/en-my/all/', '/en-my/e/botc/', '/en-my/o/boardlah/',
            '/en-my/blog/', '/en-my/blog/a-post/', '/en-my/help/', '/en-my/contact/',
            '/checkout/DRSVP-ABC', '/orders/DRSVP-ABC', '/tickets/tok123',
            // Near-misses that must not be swept up by a sloppy prefix match.
            '/en-my/administrators/', '/en-my/hosting-tips/',
        ];

        foreach ($public as $path) {
            $this->assertDoesNotMatchRegularExpression($pattern, $path, "{$path} is public and should still be measured.");
        }
    }

    public function test_the_browser_is_handed_the_rule_on_public_pages_only(): void
    {
        config(['services.ga.measurement_id' => 'G-TEST', 'services.clarity.project_id' => 'abc123']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertTrue(Tracking::clientConfig(Request::create('/en-my/'))['active']);
        // Staff areas carry nothing at all.
        $this->assertNull(Tracking::clientConfig(Request::create('/admin/overview')));
        $this->assertNull(Tracking::clientConfig(Request::create('/host/events')));
        // A buyer's private page gets the config DORMANT: no tag rendered and
        // nothing sent from it, but the guard can load the tag if this SPA
        // session goes on to a public page (sign in -> event -> checkout).
        $this->assertFalse(Tracking::clientConfig(Request::create('/dashboard'))['active']);
        $this->assertFalse(Tracking::clientConfig(Request::create('/login'))['active']);
    }

    public function test_no_rule_is_published_when_both_trackers_are_off(): void
    {
        config(['services.ga.measurement_id' => '', 'services.clarity.project_id' => '']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertNull(Tracking::clientConfig(Request::create('/en-my/')));
    }

    public function test_the_guard_config_reaches_a_public_page_and_not_the_panel(): void
    {
        config(['services.ga.measurement_id' => 'G-TEST', 'services.clarity.project_id' => 'abc123']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->publicEvent();

        $this->get('/en-my/e/botc')->assertOk()->assertSee('window.__tracking', false);

        $admin = User::factory()->create();
        Role::findOrCreate('superadmin', 'web');
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get('/admin/overview')
            ->assertOk()
            ->assertDontSee('window.__tracking', false)
            ->assertDontSee('googletagmanager', false)
            ->assertDontSee('clarity.ms', false);
    }

    // ---- the title the browser reports -------------------------------------

    public function test_the_shared_title_matches_the_one_the_server_rendered(): void
    {
        config(['seo.site_name' => 'DropRSVP', 'app.name' => 'DropRSVP']);
        $event = $this->publicEvent();

        $expected = 'Blood on the Clocktower Session – Kajang, 10 Oct 2026 | DropRSVP';

        // In the HTML head…
        $response = $this->get('/en-my/e/'.$event->slug)->assertOk();
        $response->assertSee('<title>'.$expected.'</title>', false);

        // …and handed to the SPA, so an in-app navigation sets exactly the same
        // document.title rather than the bare event name. This is what GA
        // reports as page_title, and what an SEO extension reads off the DOM.
        $response->assertInertia(fn ($page) => $page->where('pageTitle', $expected));
    }

    public function test_the_organizer_page_shares_its_templated_title_too(): void
    {
        config(['seo.site_name' => 'DropRSVP', 'app.name' => 'DropRSVP']);
        $event = $this->publicEvent();

        $this->get('/en-my/o/'.$event->user->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pageTitle', 'BoardLah Entertainment · Event Organiser | DropRSVP'));
    }

    public function test_a_per_event_override_is_shared_verbatim(): void
    {
        config(['seo.site_name' => 'DropRSVP', 'app.name' => 'DropRSVP']);
        $event = $this->publicEvent();
        $event->seo()->create(['seo_title' => 'Game night in {city}']);

        // The override wins, and the site name is appended because the override
        // does not already carry it — same rule the <title> follows.
        $this->get('/en-my/e/'.$event->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pageTitle', 'Game night in Kajang · DropRSVP'));
    }
}
