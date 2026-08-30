<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_landing_sections_save_and_appear_on_home(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.site.landing.save'), [
            'organizer' => ['enabled' => false, 'heading' => 'Host', 'body' => 'x', 'cta_label' => 'Go', 'cta_url' => '/get-started', 'image' => ''],
            'event_time' => ['enabled' => true, 'heading' => 'When', 'items' => [['label' => 'Today', 'value' => 'today']]],
            'nearby_cities' => ['enabled' => true, 'heading' => 'Near', 'cities' => ['Penang']],
        ])->assertRedirect();

        $this->get('/en-my')->assertInertia(fn (Assert $p) => $p
            ->component('welcome')
            ->where('sections.organizer.enabled', false)
            ->where('sections.event_time.enabled', true)
            ->where('sections.nearby_cities.cities.0.name', 'Penang'));
    }

    public function test_footer_saves_as_puck_data_and_is_shared_site_wide(): void
    {
        $data = ['root' => [], 'content' => [
            ['type' => 'Footer', 'props' => ['id' => 'f', 'tagline' => 'Hi', 'ctaLabel' => 'Go', 'ctaUrl' => '/x', 'columns' => [['title' => 'Discover', 'links' => [['label' => 'Events', 'url' => '/en-my']]]]]],
        ]];

        $this->actingAs($this->superadmin())->post(route('admin.site.footer.save'), ['data' => $data])->assertRedirect();

        $this->get('/en-my')->assertInertia(fn (Assert $p) => $p
            ->where('footer.content.0.type', 'Footer')
            ->where('footer.content.0.props.columns.0.title', 'Discover'));
    }

    public function test_non_superadmin_cannot_edit_site(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.site.landing'))->assertForbidden();
    }

    public function test_homepage_seo_saves_and_renders_in_head(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.site.home-seo.save'), [
            'title' => 'Custom Home Title',
            'description' => 'Custom home description for search.',
            'keywords' => 'events, tickets, kl',
        ])->assertRedirect();

        // Server-rendered into the <head> (no JS needed).
        $this->get('/en-my')
            ->assertSee('<title>Custom Home Title</title>', false)
            ->assertSee('<meta name="description" content="Custom home description for search.">', false)
            ->assertSee('<meta name="keywords" content="events, tickets, kl">', false);
    }

    public function test_homepage_seo_blank_fields_fall_back_to_defaults(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.site.home-seo.save'), [
            'title' => '', 'description' => '', 'keywords' => '',
        ])->assertRedirect();

        // A cleared title must not produce an empty <title>.
        $this->get('/en-my')->assertDontSee('<title></title>', false);
    }

    public function test_discover_when_filter_limits_by_date(): void
    {
        // Freeze the clock at midday so "starts_at = now()+2h" can't spill across a
        // day boundary (the when=today window is bounded by the app-tz calendar day).
        $this->travelTo(\Illuminate\Support\Carbon::create(2026, 6, 15, 12, 0, 0));

        $host = User::factory()->create();
        $today = \App\Models\Event::create(['user_id' => $host->id, 'title' => 'Today Ev', 'slug' => 'today-ev', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addHours(2)]);
        \App\Models\Event::create(['user_id' => $host->id, 'title' => 'Next Month', 'slug' => 'next-month', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addMonths(1)->addDays(2)]);

        $this->get('/en-my/all?when=today')->assertInertia(fn (Assert $p) => $p
            ->component('public/events/index')
            ->has('events.data', 1)
            ->where('events.data.0.slug', 'today-ev'));
    }
}
