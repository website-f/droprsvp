<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_can_create_event_with_sessions_and_ticket_types(): void
    {
        $user = $this->organizer();

        $response = $this->actingAs($user)->post('/host/events', [
            'title' => 'KL Indie Night',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_online' => false,
            'venue_name' => 'The Bee',
            'publish' => true,
            'sessions' => [
                ['title' => 'Day 1', 'starts_at' => now()->addDays(5)->toDateTimeString(), 'ends_at' => now()->addDays(5)->addHours(3)->toDateTimeString(), 'capacity' => 100],
            ],
            'ticketTypes' => [
                ['name' => 'Early Bird', 'kind' => 'paid', 'price' => 50, 'quantity' => 100, 'min_per_order' => 1, 'max_per_order' => 6, 'is_active' => true],
                ['name' => 'Free RSVP', 'kind' => 'free', 'price' => 0, 'min_per_order' => 1, 'max_per_order' => 2, 'is_active' => true],
            ],
        ]);

        $response->assertRedirect('/host/events');

        $event = Event::first();
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('published', $event->status);
        $this->assertSame('kl-indie-night', $event->slug);
        $this->assertSame(1, $event->sessions()->count());
        $this->assertSame(2, $event->ticketTypes()->count());
        $this->assertNotNull($event->starts_at);
        // free ticket is forced to price 0
        $this->assertEquals(0, $event->ticketTypes()->where('name', 'Free RSVP')->value('price'));
    }

    public function test_ticket_normal_price_and_page_toggles_persist(): void
    {
        $user = $this->organizer();

        $this->actingAs($user)->post('/host/events', [
            'title' => 'Discount Night', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'show_participants' => false, 'show_reviews' => false,
            'sessions' => [['starts_at' => now()->addDay()->toDateTimeString()]],
            'ticketTypes' => [
                ['name' => 'Deal', 'kind' => 'paid', 'price' => 30, 'compare_at_price' => 50, 'min_per_order' => 1, 'max_per_order' => 4, 'is_active' => true],
                ['name' => 'NoDeal', 'kind' => 'paid', 'price' => 30, 'compare_at_price' => 20, 'min_per_order' => 1, 'max_per_order' => 4, 'is_active' => true],
            ],
        ])->assertRedirect('/host/events');

        $event = Event::first();
        $this->assertFalse($event->show_participants);
        $this->assertFalse($event->show_reviews);
        // Higher normal price is kept; a lower-than-price "normal" is dropped.
        $this->assertEquals(50, $event->ticketTypes()->where('name', 'Deal')->value('compare_at_price'));
        $this->assertNull($event->ticketTypes()->where('name', 'NoDeal')->value('compare_at_price'));
    }

    public function test_host_can_save_an_event_gallery(): void
    {
        $user = $this->organizer();

        $this->actingAs($user)->post('/host/events', [
            'title' => 'Gallery Event', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'cover_image' => '/uploads/cover.jpg',
            'gallery' => ['/uploads/one.jpg', '/uploads/two.jpg'],
            'sessions' => [['starts_at' => now()->addDay()->toDateTimeString()]],
            'ticketTypes' => [],
            'publish' => true,
        ])->assertRedirect('/host/events');

        $event = Event::first();
        $this->assertSame(['/uploads/one.jpg', '/uploads/two.jpg'], $event->gallery);

        // Public event page receives the gallery (absolute URLs).
        $this->get('/e/'.$event->slug)->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('public/event')
            ->has('event.gallery', 2));
    }

    public function test_host_can_view_their_events_index(): void
    {
        $user = $this->organizer();
        \App\Models\Event::create([
            'user_id' => $user->id, 'title' => 'Mine', 'slug' => 'mine',
            'status' => 'draft', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        $this->actingAs($user)->get(route('host.events.index'))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('host/events/index')
                ->has('events', 1));
    }

    public function test_update_syncs_sessions_and_ticket_types(): void
    {
        $user = $this->organizer();
        $this->actingAs($user)->post('/host/events', [
            'title' => 'Sync Test', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'sessions' => [['starts_at' => now()->addDay()->toDateTimeString()]],
            'ticketTypes' => [
                ['name' => 'A', 'kind' => 'paid', 'price' => 10, 'min_per_order' => 1, 'max_per_order' => 4],
                ['name' => 'B', 'kind' => 'paid', 'price' => 20, 'min_per_order' => 1, 'max_per_order' => 4],
            ],
        ]);
        $event = Event::first();
        $this->assertSame(2, $event->ticketTypes()->count());

        $this->actingAs($user)->put("/host/events/{$event->slug}", [
            'title' => 'Sync Test', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'sessions' => [['starts_at' => now()->addDay()->toDateTimeString()]],
            'ticketTypes' => [
                ['name' => 'A only', 'kind' => 'paid', 'price' => 15, 'min_per_order' => 1, 'max_per_order' => 4],
            ],
        ]);

        $event->refresh();
        $this->assertSame(1, $event->ticketTypes()->count());
        $this->assertSame('A only', $event->ticketTypes()->first()->name);
    }

    public function test_cannot_edit_an_event_you_do_not_own(): void
    {
        $owner = $this->organizer();
        $intruder = $this->organizer();
        $this->actingAs($owner)->post('/host/events', [
            'title' => 'Owned', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'sessions' => [['starts_at' => now()->addDay()->toDateTimeString()]],
            'ticketTypes' => [],
        ]);
        $event = Event::first();

        $this->actingAs($intruder)->get("/host/events/{$event->slug}/edit")->assertForbidden();
    }
}
