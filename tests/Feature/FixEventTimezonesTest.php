<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Events saved before the timezone fix hold local time labelled as UTC. The
 * backfill re-reads that wall-clock time in the event's own timezone.
 */
class FixEventTimezonesTest extends TestCase
{
    use RefreshDatabase;

    private function brokenEvent(): Event
    {
        $event = Event::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Clocktower', 'slug' => 'clocktower', 'status' => 'published',
            'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        // Straight to the table: 3pm local wrongly stored as 3pm UTC, exactly as
        // the old code left it.
        DB::table('events')->where('id', $event->id)->update([
            'starts_at' => '2026-10-10 15:00:00',
            'ends_at' => '2026-10-10 22:30:00',
        ]);
        $session = $event->sessions()->create(['starts_at' => now(), 'sort_order' => 0]);
        DB::table('event_sessions')->where('id', $session->id)->update([
            'starts_at' => '2026-10-10 15:00:00',
            'ends_at' => '2026-10-10 22:30:00',
        ]);

        return $event->fresh();
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $event = $this->brokenEvent();

        $this->artisan('events:fix-timezones', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('2026-10-10 15:00:00', $event->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_it_shifts_the_stored_instant_back_by_the_events_offset(): void
    {
        $event = $this->brokenEvent();

        $this->artisan('events:fix-timezones')->assertSuccessful();

        // 3pm Kuala Lumpur is 07:00 UTC — the value the app would store today.
        $this->assertSame('2026-10-10 07:00:00', $event->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-10 14:30:00', $event->fresh()->ends_at->utc()->format('Y-m-d H:i:s'));

        $session = $event->fresh()->sessions()->firstOrFail();
        $this->assertSame('2026-10-10 07:00:00', $session->starts_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_public_page_then_shows_the_intended_time(): void
    {
        $event = $this->brokenEvent();

        $this->artisan('events:fix-timezones')->assertSuccessful();

        $this->get('/en-my/e/'.$event->slug.'/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('event.when', 'Sat, 10 Oct 2026 · 3:00 PM'));
    }

    public function test_a_utc_event_is_left_alone(): void
    {
        $event = Event::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'UTC one', 'slug' => 'utc-one', 'status' => 'published',
            'visibility' => 'public', 'timezone' => 'UTC',
        ]);
        DB::table('events')->where('id', $event->id)->update(['starts_at' => '2026-10-10 15:00:00']);

        $this->artisan('events:fix-timezones')->assertSuccessful();

        // Zero offset, so nothing to shift.
        $this->assertSame('2026-10-10 15:00:00', $event->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
    }
}
