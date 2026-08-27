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
        $user = User::factory()->create();

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

    public function test_update_syncs_sessions_and_ticket_types(): void
    {
        $user = User::factory()->create();
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
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->actingAs($owner)->post('/host/events', [
            'title' => 'Owned', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'sessions' => [['starts_at' => now()->addDay()->toDateTimeString()]],
            'ticketTypes' => [],
        ]);
        $event = Event::first();

        $this->actingAs($intruder)->get("/host/events/{$event->slug}/edit")->assertForbidden();
    }
}
