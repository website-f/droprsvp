<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An organizer types a time in their event's timezone; the public page must show
 * that same time back.
 *
 * It did not. A datetime-local input posts "2026-10-10T15:00" with no timezone
 * attached, and that string was handed straight to the model, where Laravel
 * parsed it in config('app.timezone') = UTC. So 3pm in Kuala Lumpur was stored
 * as 3pm UTC, and the public page — which converts UTC into the event's
 * timezone — showed 11pm.
 *
 * The edit form hid it: it sliced the ISO string and ignored the timezone too,
 * echoing back exactly what had been typed. Both ends are covered here, because
 * fixing only one would make the round trip wrong in the other direction.
 */
class EventTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Clocktower',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_online' => false,
            'venue_name' => 'HOL Cafe',
            'publish' => true,
            'sessions' => [
                ['starts_at' => '2026-10-10T15:00', 'ends_at' => '2026-10-10T22:30', 'capacity' => 25],
            ],
            'ticketTypes' => [
                ['name' => 'GA', 'kind' => 'free', 'price' => 0, 'min_per_order' => 1, 'max_per_order' => 4, 'is_active' => true],
            ],
        ], $overrides);
    }

    public function test_a_local_time_is_stored_as_the_right_utc_instant(): void
    {
        $this->actingAs($this->organizer())->post('/host/events', $this->payload())->assertRedirect();

        $session = Event::firstOrFail()->sessions()->firstOrFail();

        // 15:00 in Kuala Lumpur (UTC+8) is 07:00 UTC — not 15:00 UTC.
        $this->assertSame('2026-10-10 07:00:00', $session->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-10 14:30:00', $session->ends_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_public_page_shows_the_time_that_was_typed(): void
    {
        $this->actingAs($this->organizer())->post('/host/events', $this->payload())->assertRedirect();

        $event = Event::firstOrFail();

        // The reported symptom: a 3pm session displayed as 11:00 PM.
        $this->get('/en-my/e/'.$event->slug.'/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('event.when', 'Sat, 10 Oct 2026 · 3:00 PM'));
    }

    public function test_the_events_own_window_matches_its_sessions(): void
    {
        $this->actingAs($this->organizer())->post('/host/events', $this->payload())->assertRedirect();

        $event = Event::firstOrFail();

        // starts_at/ends_at are the min/max of the sessions, so they must be
        // converted too rather than left as the raw submitted strings.
        $this->assertSame('2026-10-10 07:00:00', $event->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-10 14:30:00', $event->ends_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_edit_form_shows_the_time_back_in_the_events_timezone(): void
    {
        $host = $this->organizer();
        $this->actingAs($host)->post('/host/events', $this->payload())->assertRedirect();

        $event = Event::firstOrFail();

        // Without the *_local fields the input would show 07:00 — correct UTC,
        // wrong thing to put in a box with no timezone on it.
        $this->actingAs($host)
            ->get('/host/events/'.$event->slug.'/edit')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('event.sessions.0.starts_at_local', '2026-10-10T15:00')
                ->where('event.sessions.0.ends_at_local', '2026-10-10T22:30'));
    }

    public function test_saving_an_unchanged_event_does_not_shift_its_times(): void
    {
        $host = $this->organizer();
        $this->actingAs($host)->post('/host/events', $this->payload())->assertRedirect();

        $event = Event::firstOrFail();

        // Re-submitting exactly what the edit form shows must be a no-op. If the
        // two ends disagreed, every save would walk the time by 8 hours.
        $this->actingAs($host)->put('/host/events/'.$event->slug, $this->payload([
            'sessions' => [[
                'id' => $event->sessions()->value('id'),
                'starts_at' => '2026-10-10T15:00',
                'ends_at' => '2026-10-10T22:30',
                'capacity' => 25,
            ]],
        ]))->assertRedirect();

        $this->assertSame(
            '2026-10-10 07:00:00',
            $event->fresh()->sessions()->firstOrFail()->starts_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_different_timezone_gets_its_own_offset(): void
    {
        $this->actingAs($this->organizer())
            ->post('/host/events', $this->payload(['timezone' => 'Asia/Tokyo']))
            ->assertRedirect();

        // Tokyo is UTC+9, so 15:00 there is 06:00 UTC.
        $this->assertSame(
            '2026-10-10 06:00:00',
            Event::firstOrFail()->sessions()->firstOrFail()->starts_at->utc()->format('Y-m-d H:i:s'),
        );
    }
}
