<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicEventTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(array $overrides = []): Event
    {
        $user = User::factory()->create();

        return Event::create(array_merge([
            'user_id' => $user->id,
            'title' => 'Public Show',
            'slug' => 'public-show',
            'status' => 'published',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ], $overrides));
    }

    public function test_published_public_event_is_viewable_with_seo_schema(): void
    {
        $event = $this->makeEvent();
        $event->ticketTypes()->create(['name' => 'GA', 'kind' => 'paid', 'price' => 30, 'quantity' => 50]);

        $this->get("/e/{$event->slug}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/event')
                ->where('event.title', 'Public Show')
                ->where('schema.@type', 'Event')
                ->has('event.ticket_types', 1)
                ->has('seo.description')
            );
    }

    public function test_draft_event_is_hidden_from_guests(): void
    {
        $event = $this->makeEvent(['status' => 'draft']);
        $this->get("/e/{$event->slug}")->assertNotFound();
    }

    public function test_owner_can_preview_their_own_draft(): void
    {
        $event = $this->makeEvent(['status' => 'draft']);
        $this->actingAs($event->user)->get("/e/{$event->slug}")->assertOk();
    }

    public function test_private_event_is_hidden_from_guests(): void
    {
        $event = $this->makeEvent(['visibility' => 'private']);
        $this->get("/e/{$event->slug}")->assertNotFound();
    }
}
