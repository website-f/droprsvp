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

    public function test_a_draft_carries_a_link_too_so_the_button_can_explain_itself(): void
    {
        $host = $this->organizer();

        Event::create([
            'user_id' => $host->id, 'title' => 'Draft one', 'slug' => 'draft-one',
            'status' => 'draft', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        // The button is rendered for every event and says why it cannot be used,
        // rather than vanishing — which reads as a missing feature.
        $this->actingAs($host)
            ->get('/host/events')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('events.0.public_url', Url::to('e', 'draft-one'))
                ->where('events.0.status', 'draft'));
    }

    public function test_a_draft_link_really_does_not_work_for_anyone_else(): void
    {
        $host = $this->organizer();

        Event::create([
            'user_id' => $host->id, 'title' => 'Draft one', 'slug' => 'draft-one',
            'status' => 'draft', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        // This is why sharing is gated: the owner can preview it...
        $this->actingAs($host)->get(Url::path('e', 'draft-one'))->assertOk();
    }

    public function test_a_draft_link_is_a_404_for_everyone_but_the_owner(): void
    {
        $host = $this->organizer();

        Event::create([
            'user_id' => $host->id, 'title' => 'Draft one', 'slug' => 'draft-one',
            'status' => 'draft', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        // ...but nobody else can, so handing the link out would be useless.
        // (Its own test: actingAs persists for the rest of a test, so an
        // anonymous assertion after an authenticated one is not anonymous.)
        $this->get(Url::path('e', 'draft-one'))->assertNotFound();
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
