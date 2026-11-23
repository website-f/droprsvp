<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An event on several dates — every Sunday for a month — shows and acts on
 * its NEXT date, not its first: once the first Sunday had passed, every page
 * showed a date in the past and the event looked over.
 */
class RecurringEventTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 23 Nov 2026, 10am in Kuala Lumpur — the first Sunday was yesterday.
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00', 'Asia/Kuala_Lumpur'));

        $host = $this->organizer();
        $this->event = Event::create([
            'user_id' => $host->id, 'title' => 'Student of The Universe', 'slug' => 'student-of-the-universe',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => Carbon::parse('2026-11-22 20:00', 'Asia/Kuala_Lumpur')->utc(),
            'ends_at' => Carbon::parse('2026-12-13 22:00', 'Asia/Kuala_Lumpur')->utc(),
        ]);

        foreach (['2026-11-22', '2026-11-29', '2026-12-06', '2026-12-13'] as $i => $day) {
            $this->event->sessions()->create([
                'starts_at' => Carbon::parse("$day 20:00", 'Asia/Kuala_Lumpur')->utc(),
                'ends_at' => Carbon::parse("$day 22:00", 'Asia/Kuala_Lumpur')->utc(),
                'sort_order' => $i,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_next_date_and_the_pattern(): void
    {
        $this->assertSame('2026-11-29 20:00', $this->event->nextStartsAt()->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
        $this->assertSame('Every Sunday', $this->event->repeatLabel());
        $this->assertFalse($this->event->hasEnded());

        // During the last one, it is the last one; after it, the event is over.
        Carbon::setTestNow(Carbon::parse('2026-12-13 21:00', 'Asia/Kuala_Lumpur'));
        $this->assertSame('2026-12-13', $this->event->fresh()->nextStartsAt()->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d'));
        Carbon::setTestNow(Carbon::parse('2026-12-14 09:00', 'Asia/Kuala_Lumpur'));
        $this->assertTrue($this->event->fresh()->hasEnded());
    }

    public function test_other_patterns_are_named_too(): void
    {
        $make = function (array $days) {
            $e = Event::create(['user_id' => $this->event->user_id, 'title' => Str::random(8), 'slug' => Str::random(8), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
            foreach ($days as $d) {
                $e->sessions()->create(['starts_at' => Carbon::parse("$d 19:00", 'Asia/Kuala_Lumpur')->utc()]);
            }

            return $e->fresh();
        };

        $this->assertSame('Every other Saturday', $make(['2026-11-28', '2026-12-12', '2026-12-26'])->repeatLabel());
        $this->assertSame('Every day', $make(['2026-12-01', '2026-12-02', '2026-12-03'])->repeatLabel());
        $this->assertSame('Every month on the 5th', $make(['2026-12-05', '2027-01-05', '2027-02-05'])->repeatLabel());
        $this->assertSame('3 dates', $make(['2026-12-01', '2026-12-04', '2026-12-20'])->repeatLabel());
        $this->assertNull($make(['2026-12-01'])->repeatLabel());
    }

    public function test_the_event_page_shows_the_next_date_and_dims_the_ones_held(): void
    {
        $this->get('/en-my/e/student-of-the-universe/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('event.repeat', 'Every Sunday')
            ->where('event.when', 'Sun, 29 Nov 2026 · 8:00 PM')
            ->where('event.has_ended', false)
            ->where('event.sessions.0.past', true)
            ->where('event.sessions.1.past', false));
    }

    public function test_listings_and_the_organizers_list_show_the_next_date(): void
    {
        $this->get('/en-my/all/')->assertInertia(fn (Assert $page) => $page
            ->where('events.data.0.when', 'Sun, 29 Nov 2026')
            ->where('events.data.0.repeat', 'Every Sunday'));

        $this->actingAs($this->event->user)->get('/host/events')->assertInertia(fn (Assert $page) => $page
            ->where('events.0.repeat', 'Every Sunday')
            ->where('events.0.next_starts_at', Carbon::parse('2026-11-29 20:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String()));
    }

    public function test_a_ticket_can_still_be_cancelled_before_the_next_date(): void
    {
        $buyer = User::factory()->create();
        $order = Order::create(['reference' => 'DRSVP-REC1', 'event_id' => $this->event->id, 'user_id' => $buyer->id, 'status' => 'paid', 'total' => 0, 'paid_at' => now(), 'buyer_name' => 'Aina', 'buyer_email' => $buyer->email]);
        $order->tickets()->create(['event_id' => $this->event->id, 'attendee_name' => 'Aina', 'status' => 'valid']);

        $this->actingAs($buyer)->get('/my/tickets')->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.upcoming', true)
            ->where('orders.data.0.event.when', 'Sun, 29 Nov 2026 · 8:00 PM'));
    }

    public function test_one_date_running_over_several_days_shows_its_whole_span(): void
    {
        $tz = 'Asia/Kuala_Lumpur';
        $block = Event::create([
            'user_id' => $this->event->user_id, 'title' => 'Art Week', 'slug' => 'art-week',
            'status' => 'published', 'visibility' => 'public', 'timezone' => $tz,
            'starts_at' => Carbon::parse('2026-11-22 08:30', $tz)->utc(),
            'ends_at' => Carbon::parse('2026-12-13 10:00', $tz)->utc(),
        ]);
        $block->sessions()->create(['starts_at' => $block->starts_at, 'ends_at' => $block->ends_at]);

        $this->assertSame('Sun, 22 Nov – Sun, 13 Dec 2026', $block->fresh()->displayDate());
        $this->assertSame('Sun, 22 Nov – Sun, 13 Dec 2026 · 8:30 AM', $block->fresh()->displayDate(true));
        $this->assertNull($block->fresh()->repeatLabel(), 'one continuous date is not a repeat');

        // Across a new year, both years.
        $block->sessions()->update(['ends_at' => Carbon::parse('2027-01-03 10:00', $tz)->utc()]);
        $block->update(['ends_at' => Carbon::parse('2027-01-03 10:00', $tz)->utc()]);
        $this->assertSame('Sun, 22 Nov 2026 – Sun, 3 Jan 2027', $block->fresh()->displayDate());

        // A one-evening event stays a single date.
        $this->assertSame('Sun, 29 Nov 2026', $this->event->fresh()->displayDate());

        $this->get('/en-my/e/art-week/')->assertInertia(fn (Assert $page) => $page->where('event.when', 'Sun, 22 Nov 2026 – Sun, 3 Jan 2027 · 8:30 AM'));
    }
}
