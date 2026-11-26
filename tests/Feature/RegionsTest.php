<?php

namespace Tests\Feature;

use App\Mail\OrganizerApplicationMail;
use App\Models\Event;
use App\Models\Setting;
use App\Models\User;
use App\Support\Regions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regions (en-my, en-sg…): admin turns them on, events belong to one, each
 * region's pages list only its own events — and organizers can be approved
 * automatically.
 */
class RegionsTest extends TestCase
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

    private function event(string $region, string $title, array $attrs = []): Event
    {
        return Event::create(array_merge([
            'user_id' => User::factory()->create()->id, 'title' => $title, 'slug' => Str::slug($title),
            'status' => 'published', 'visibility' => 'public', 'region' => $region,
            'timezone' => $region === 'en-sg' ? 'Asia/Singapore' : 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addWeek(),
        ], $attrs));
    }

    public function test_southeast_asia_is_on_out_of_the_box_with_malaysia_the_default(): void
    {
        $codes = Regions::codes();

        $this->assertSame('en-my', $codes[0]);
        foreach (['en-sg', 'en-id', 'en-th', 'en-ph', 'en-vn'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertSame('en-my', Regions::default()['code']);
    }

    public function test_the_admin_adds_regions_in_bulk_and_picks_the_default(): void
    {
        $this->actingAs($this->superadmin())->post('/admin/settings/regions', [
            'enabled' => ['MY', 'SG', 'JP', 'GB', 'AU'],
            'default' => 'SG',
        ])->assertSessionHasNoErrors();

        $this->assertSame('en-sg', Regions::default()['code']);
        $this->assertSame(['en-sg', 'en-au', 'en-jp', 'en-my', 'en-gb'], Regions::codes(), 'default first, then by name');
    }

    public function test_the_default_must_be_a_region_that_is_on(): void
    {
        $this->actingAs($this->superadmin())->post('/admin/settings/regions', ['enabled' => ['MY'], 'default' => 'SG'])
            ->assertSessionHasErrors('default');
        $this->actingAs($this->superadmin())->post('/admin/settings/regions', ['enabled' => [], 'default' => 'MY'])
            ->assertSessionHasErrors('enabled');
        $this->actingAs($this->superadmin())->post('/admin/settings/regions', ['enabled' => ['ZZ'], 'default' => 'ZZ'])
            ->assertSessionHasErrors('enabled.0');
    }

    public function test_each_region_lists_only_its_own_events(): void
    {
        $this->event('en-my', 'Board Game Night KL');
        $this->event('en-sg', 'Marina Bay Trivia', ['city' => 'Singapore']);

        $this->get('/en-sg/all/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('region.code', 'en-sg')
            ->has('events.data', 1)
            ->where('events.data.0.title', 'Marina Bay Trivia'));

        $this->get('/en-my/all/')->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.title', 'Board Game Night KL'));

        // A Singapore city page, built from where Singapore's events are.
        $this->get('/en-sg/singapore/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
        $this->get('/en-sg/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('cities.0.name', 'Singapore'));
    }

    public function test_an_event_lives_at_its_own_regions_address(): void
    {
        $event = $this->event('en-sg', 'Marina Bay Trivia');

        $this->get('/en-sg/e/marina-bay-trivia/')->assertOk();
        $this->get('/en-my/e/marina-bay-trivia/?ref=ig')->assertRedirect($this->abs('/en-sg/e/marina-bay-trivia/').'?ref=ig')->assertStatus(301);

        $html = $this->get('/en-sg/e/marina-bay-trivia/')->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.$this->abs('/en-sg/e/marina-bay-trivia/').'"', $html);
        $this->assertStringContainsString('lang="en-SG"', $html);
        $this->assertStringContainsString('"addressCountry":"SG"', $html);
        $this->assertStringContainsString('<loc>'.$this->abs('/en-sg/e/marina-bay-trivia/').'</loc>', $this->get('/event-sitemap.xml')->getContent());
    }

    public function test_a_region_that_is_off_falls_back_to_the_default(): void
    {
        $this->get('/en-jp/all/?q=jazz')->assertRedirect($this->abs('/en-my/all/').'?q=jazz');
    }

    public function test_the_home_page_follows_the_visitor(): void
    {
        $this->get('/')->assertRedirect($this->abs('/en-my/'));
        $this->withHeader('Accept-Language', 'en-SG,en;q=0.9')->get('/')->assertRedirect($this->abs('/en-sg/'));
        $this->withCookie('region', 'en-th')->get('/')->assertRedirect($this->abs('/en-th/'));

        // Browsing a region remembers it.
        $this->get('/en-id/all/')->assertCookie('region', 'en-id');
    }

    public function test_content_that_is_not_about_a_place_has_one_canonical(): void
    {
        $html = $this->get('/en-sg/contact/')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.$this->abs('/en-my/contact/').'"', $html);
    }

    public function test_an_organizer_puts_an_event_in_a_region_and_it_keeps_that_regions_time(): void
    {
        $host = $this->organizer();

        $this->actingAs($host)->post(route('host.events.store'), [
            'title' => 'Marina Bay Trivia', 'visibility' => 'public', 'region' => 'en-sg',
            // A Malaysian clock sent by mistake: a Singapore event keeps Singapore time.
            'timezone' => 'Asia/Kuala_Lumpur',
            'ticketTypes' => [['name' => 'Entry', 'kind' => 'free', 'price' => '0', 'min_per_order' => 1, 'max_per_order' => 5, 'is_active' => true]],
            'sessions' => [], 'gallery' => [], 'sections' => [],
        ])->assertSessionHasNoErrors();

        $event = Event::where('title', 'Marina Bay Trivia')->firstOrFail();
        $this->assertSame('en-sg', $event->region);
        $this->assertSame('Asia/Singapore', $event->timezone);

        // A region that is not on is refused.
        $this->actingAs($host)->post(route('host.events.store'), [
            'title' => 'Tokyo Night', 'visibility' => 'public', 'region' => 'en-jp', 'timezone' => 'Asia/Tokyo',
            'ticketTypes' => [['name' => 'Entry', 'kind' => 'free', 'price' => '0', 'min_per_order' => 1, 'max_per_order' => 5, 'is_active' => true]],
            'sessions' => [], 'gallery' => [], 'sections' => [],
        ])->assertSessionHasErrors('region');

        // The form suggests their last region next time.
        $this->actingAs($host)->get(route('host.events.create'))->assertInertia(fn (Assert $page) => $page
            ->where('suggestedRegion', 'en-sg')
            ->where('regions.0.code', 'en-my'));
    }

    public function test_an_edit_keeps_the_events_region(): void
    {
        $host = $this->organizer();
        $event = $this->event('en-sg', 'Marina Bay Trivia', ['user_id' => $host->id]);

        $this->actingAs($host)->put(route('host.events.update', $event), [
            'title' => 'Marina Bay Trivia Night', 'visibility' => 'public', 'timezone' => 'Asia/Singapore',
            'ticketTypes' => [['name' => 'Entry', 'kind' => 'free', 'price' => '0', 'min_per_order' => 1, 'max_per_order' => 5, 'is_active' => true]],
            // A published event keeps a date (it is required once live).
            'sessions' => [['starts_at' => now()->addWeek()->format('Y-m-d').'T19:00']], 'gallery' => [], 'sections' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame('en-sg', $event->fresh()->region);
    }

    // ---- auto-approve -------------------------------------------------------------------

    private function apply(User $user)
    {
        return $this->actingAs($user)->post('/host/apply', [
            'business_name' => 'BoardLah Entertainment', 'email' => 'hello@boardlah.test', 'phone' => '0123456789',
        ]);
    }

    public function test_applications_wait_for_review_by_default(): void
    {
        Mail::fake();
        $user = $this->organizer();

        $this->apply($user)->assertRedirect(route('host.pending'));
        $this->assertSame('pending', $user->fresh()->organizerProfile->status);
    }

    public function test_with_auto_approve_on_an_organizer_can_create_events_straight_away(): void
    {
        Mail::fake();
        $this->actingAs($this->superadmin())->post('/admin/settings', $this->settingsPayload(['organizer_auto_approve' => true]))->assertSessionHasNoErrors();
        $this->assertSame('1', Setting::get('organizer_auto_approve'));

        $user = $this->organizer();
        $this->apply($user)->assertRedirect(route('host.events.index'));

        $profile = $user->fresh()->organizerProfile;
        $this->assertSame('approved', $profile->status);
        $this->assertNotNull($profile->reviewed_at);
        $this->assertNotNull($user->fresh()->slug);
        Mail::assertSent(OrganizerApplicationMail::class, fn ($m) => $m->approved === true);

        $this->actingAs($user->fresh())->get(route('host.events.create'))->assertOk();
    }

    /** The full settings form, as the page posts it. */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'fee_percent' => 5, 'fee_flat' => 2, 'boost_price' => 10, 'boost_days' => 7,
            'premium_price' => 50, 'premium_days' => 30, 'tax_percent' => 0, 'tax_label' => 'SST',
            'tax_inclusive' => false, 'support_email' => '', 'trending_keywords' => '',
            'ticketing_modes' => ['general' => true, 'reserved' => true, 'tables' => true],
            'announcement' => ['active' => false, 'style' => 'banner', 'level' => 'info', 'title' => '', 'body' => '', 'cta_label' => '', 'cta_url' => ''],
        ], $overrides);
    }
}
