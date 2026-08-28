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

        $this->get('/')->assertInertia(fn (Assert $p) => $p
            ->component('welcome')
            ->where('sections.organizer.enabled', false)
            ->where('sections.event_time.enabled', true)
            ->where('sections.nearby_cities.cities.0', 'Penang'));
    }

    public function test_footer_saves_and_is_shared_site_wide(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.site.footer.save'), [
            'tagline' => 'Our tagline',
            'copyright' => '© Test',
            'columns' => [['title' => 'Discover', 'links' => [['label' => 'Events', 'url' => '/events']]]],
        ])->assertRedirect();

        $this->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('footer.tagline', 'Our tagline')
            ->where('footer.columns.0.title', 'Discover'));
    }

    public function test_non_superadmin_cannot_edit_site(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.site.landing'))->assertForbidden();
    }

    public function test_discover_when_filter_limits_by_date(): void
    {
        $host = User::factory()->create();
        $today = \App\Models\Event::create(['user_id' => $host->id, 'title' => 'Today Ev', 'slug' => 'today-ev', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addHours(2)]);
        \App\Models\Event::create(['user_id' => $host->id, 'title' => 'Next Month', 'slug' => 'next-month', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addMonths(1)->addDays(2)]);

        $this->get('/events?when=today')->assertInertia(fn (Assert $p) => $p
            ->component('public/events/index')
            ->has('events.data', 1)
            ->where('events.data.0.slug', 'today-ev'));
    }
}
