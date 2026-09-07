<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Support\SeoTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeoTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function publishedEvent(): Event
    {
        $host = User::factory()->create();

        return Event::create([
            'user_id' => $host->id, 'title' => 'Neon Nights', 'slug' => 'neon-nights',
            'description' => 'Four acts under the stars.', 'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'city' => 'Kuala Lumpur', 'venue_name' => 'Sky Bar',
            'starts_at' => now()->addDays(10),
        ]);
    }

    public function test_render_substitutes_tokens(): void
    {
        $event = $this->publishedEvent();

        $this->assertSame('Visit Neon Nights in Kuala Lumpur', SeoTemplate::render('Visit {event_name} in {city}', $event));
        $this->assertSame('Sky Bar', SeoTemplate::render('{venue}', $event));
        // Empty tokens don't leave doubled spaces.
        $noCat = SeoTemplate::render('{event_name} {category} tickets', $event);
        $this->assertSame('Neon Nights tickets', $noCat);
    }

    public function test_event_page_renders_resolved_seo_title(): void
    {
        $event = $this->publishedEvent();
        $event->seo()->create(['seo_title' => 'Visit {event_name} in {city}']);

        $this->get('/en-my/e/neon-nights')
            ->assertOk()
            ->assertSee('Visit Neon Nights in Kuala Lumpur', false);
    }

    public function test_seo_editor_exposes_template_chips(): void
    {
        $event = $this->publishedEvent();
        $admin = User::factory()->create();
        Role::findOrCreate('superadmin', 'web');
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get(route('admin.seo.events.edit', $event))
            ->assertInertia(fn ($p) => $p->has('templateTokens')->where('templateValues.{event_name}', 'Neon Nights'));
    }
}
