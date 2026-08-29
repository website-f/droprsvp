<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EventSeoTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function event(): Event
    {
        return Event::create([
            'user_id' => User::factory()->create()->id, 'title' => 'Neon Nights', 'slug' => 'neon-nights',
            'description' => 'A rooftop live night.', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addWeek(),
        ]);
    }

    public function test_superadmin_sees_the_events_seo_list(): void
    {
        $this->event();

        $this->actingAs($this->superadmin())->get('/admin/seo/events')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/seo/events/index')->has('events.data', 1));
    }

    public function test_superadmin_can_edit_event_seo_and_it_renders_publicly(): void
    {
        $event = $this->event();

        $this->actingAs($this->superadmin())->put("/admin/seo/events/{$event->slug}", [
            'slug' => 'neon-nights',
            'seo' => [
                'seo_title' => 'Neon Nights — Rooftop Live in KL',
                'meta_description' => 'The best rooftop party this season.',
                'meta_keywords' => 'neon, rooftop, live music',
                'robots_index' => true, 'robots_follow' => true,
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('seo_meta', ['seoable_id' => $event->id, 'seoable_type' => Event::class, 'seo_title' => 'Neon Nights — Rooftop Live in KL']);

        // Server-rendered into the public page head.
        $this->get('/en-my/e/neon-nights')
            ->assertSee('<title>Neon Nights — Rooftop Live in KL', false)
            ->assertSee('<meta name="keywords" content="neon, rooftop, live music">', false);
    }

    public function test_changing_the_slug_updates_the_event_url(): void
    {
        $event = $this->event();

        $this->actingAs($this->superadmin())->put("/admin/seo/events/{$event->slug}", [
            'slug' => 'Neon Nights KL!!', 'seo' => ['robots_index' => true, 'robots_follow' => true],
        ])->assertSessionHasNoErrors();

        $this->assertSame('neon-nights-kl', $event->fresh()->slug);
    }

    public function test_a_non_superadmin_cannot_manage_event_seo(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/seo/events')->assertForbidden();
    }
}
