<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Event artwork is shown at the shape it was uploaded in, in BOTH image slots.
 *
 * Fixing only the banner was not enough: most events have no banner and show
 * their cover instead, so the fixed-ratio crop simply moved to the other slot
 * and the poster was still cut in half.
 *
 * These assert the server hands both URLs to the page. The ratio itself is a
 * CSS concern (EventBanner uses object-contain, not object-cover) and is not
 * something a request test can see — what it can protect is that neither slot
 * silently stops being delivered.
 */
class EventArtworkTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $images): Event
    {
        return Event::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'title' => 'Clocktower', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ], $images));
    }

    public function test_an_event_with_only_a_cover_still_gets_its_artwork(): void
    {
        // The reported event: no banner, poster uploaded as the cover.
        $this->event(['cover_image' => 'https://img.test/poster.png']);

        $this->get('/en-my/e/clocktower/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('event.cover_image', 'https://img.test/poster.png')
                ->where('event.banner_image', null));
    }

    public function test_an_event_with_a_banner_gets_that_too(): void
    {
        $this->event([
            'banner_image' => 'https://img.test/banner.png',
            'cover_image' => 'https://img.test/poster.png',
        ]);

        $this->get('/en-my/e/clocktower/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('event.banner_image', 'https://img.test/banner.png')
                ->where('event.cover_image', 'https://img.test/poster.png'));
    }

    public function test_an_event_with_no_artwork_renders_fine(): void
    {
        $this->event([]);

        $this->get('/en-my/e/clocktower/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('event.banner_image', null)
                ->where('event.cover_image', null));
    }
}
