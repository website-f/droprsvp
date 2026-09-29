<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organizers had no way to get their own registration link out of the dashboard
 * — they were copying it out of the address bar after opening the public page.
 */
class HostEventShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_event_list_carries_the_public_registration_link(): void
    {
        $host = $this->organizer();

        Event::create([
            'user_id' => $host->id, 'title' => 'Clocktower', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);

        $this->actingAs($host)
            ->get('/host/events')
            ->assertOk()
            // Built server-side so it is the canonical, trailing-slash URL
            // rather than whatever host the dashboard happens to be on.
            ->assertInertia(fn ($p) => $p->where('events.0.public_url', Url::to('e', 'clocktower')));
    }

    public function test_the_shared_link_actually_resolves(): void
    {
        $host = $this->organizer();

        Event::create([
            'user_id' => $host->id, 'title' => 'Clocktower', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);

        // A share button that hands out a 404 would be worse than none.
        $this->get(Url::path('e', 'clocktower'))->assertOk()->assertSee('Clocktower', false);
    }
}
