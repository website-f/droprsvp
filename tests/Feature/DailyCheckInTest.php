<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Checking people in every day of a multi-day event, and the attendance it
 * records — the week-long volunteer programme of ~800 people.
 */
class DailyCheckInTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        // 9am Monday in Kuala Lumpur.
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Asia/Kuala_Lumpur'));

        $this->host = $this->organizer();
        $this->event = Event::create([
            'user_id' => $this->host->id, 'title' => 'Sukarelawan Week', 'slug' => 'sukarelawan-week',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            // Stored in UTC, as the event form stores them.
            'starts_at' => Carbon::parse('2026-11-16 08:00', 'Asia/Kuala_Lumpur')->utc(),
            'ends_at' => Carbon::parse('2026-11-20 17:00', 'Asia/Kuala_Lumpur')->utc(),
            'daily_checkin' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(string $name = 'Aina', string $status = 'valid', ?Event $event = null): Ticket
    {
        $event ??= $this->event;
        $order = Order::create(['reference' => 'DRSVP-'.Str::upper(Str::random(8)), 'event_id' => $event->id, 'status' => 'paid', 'total' => 0, 'paid_at' => now(), 'buyer_name' => $name, 'buyer_email' => Str::lower($name).'@example.test']);

        return $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => $name, 'status' => $status]);
    }

    private function scan(Ticket $ticket, ?Event $event = null)
    {
        $event ??= $this->event;

        return $this->actingAs($this->host)->postJson("/host/events/{$event->slug}/attendees/scan", ['token' => $ticket->qr_token, 'auto' => true]);
    }

    public function test_a_ticket_checks_in_once_a_day_and_again_the_next_day(): void
    {
        $t = $this->ticket();

        $this->scan($t)->assertJson(['result' => 'ok'])->assertJsonPath('stats.checked_in', 1);
        $this->scan($t)->assertJson(['result' => 'already'])->assertJsonPath('message', 'Already checked in today at 9:00 AM.');

        // Tuesday: a new day, so in again — and the count is today's, back from zero.
        Carbon::setTestNow(Carbon::parse('2026-11-17 08:45', 'Asia/Kuala_Lumpur'));
        $this->scan($t)->assertJson(['result' => 'ok'])->assertJsonPath('stats.checked_in', 1)
            ->assertJsonPath('message', 'Checked in for Tue, 17 Nov · day 2 attended.');

        $this->assertSame(['2026-11-16', '2026-11-17'], CheckIn::where('ticket_id', $t->id)->orderBy('day')->pluck('day')->map(fn ($d) => substr($d, 0, 10))->all());
    }

    public function test_the_day_is_the_events_own_day_not_utc(): void
    {
        $t = $this->ticket();

        // 7:30am in Kuala Lumpur is still the previous day in UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-17 07:30', 'Asia/Kuala_Lumpur'));
        $this->scan($t)->assertJson(['result' => 'ok']);

        $this->assertSame('2026-11-17', substr(CheckIn::where('ticket_id', $t->id)->value('day'), 0, 10));
    }

    public function test_two_phones_scanning_at_once_record_one_check_in(): void
    {
        $t = $this->ticket();

        // The other phone's insert landed first.
        CheckIn::create(['event_id' => $this->event->id, 'ticket_id' => $t->id, 'day' => '2026-11-16', 'checked_in_at' => now(), 'via' => 'scan']);

        $this->scan($t)->assertOk()->assertJson(['result' => 'already']);
        $this->assertSame(1, CheckIn::where('ticket_id', $t->id)->count());
    }

    public function test_once_only_events_still_check_in_once(): void
    {
        $event = Event::create([
            'user_id' => $this->host->id, 'title' => 'Gala', 'slug' => 'gala',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now(),
        ]);
        $t = $this->ticket('Badrul', 'valid', $event);

        $this->scan($t, $event)->assertJson(['result' => 'ok']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00', 'Asia/Kuala_Lumpur'));
        $this->scan($t, $event)->assertJson(['result' => 'already']);

        // Still on record, with who scanned it.
        $this->assertDatabaseHas('check_ins', ['ticket_id' => $t->id, 'checked_in_by' => $this->host->id]);
    }

    public function test_cancelled_and_refunded_tickets_are_turned_away(): void
    {
        foreach (['cancelled', 'refunded', 'void'] as $status) {
            $this->scan($this->ticket(ucfirst($status), $status))->assertJson(['result' => 'invalid', 'message' => "This ticket is {$status}."]);
        }

        $this->assertSame(0, CheckIn::count());
    }

    public function test_undo_takes_back_only_todays_check_in(): void
    {
        $t = $this->ticket();
        $this->scan($t);
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00', 'Asia/Kuala_Lumpur'));
        $this->scan($t);

        $this->actingAs($this->host)->postJson("/host/events/{$this->event->slug}/attendees/{$t->id}/undo")->assertOk();

        $this->assertSame(['2026-11-16'], CheckIn::where('ticket_id', $t->id)->pluck('day')->map(fn ($d) => substr($d, 0, 10))->all());
        $this->assertSame('checked_in', $t->fresh()->status, 'still attended Monday');

        // Nothing left today to undo.
        $this->actingAs($this->host)->postJson("/host/events/{$this->event->slug}/attendees/{$t->id}/undo")->assertStatus(422);
    }

    public function test_the_attendee_list_means_today_on_a_daily_event(): void
    {
        $in = $this->ticket('Aina');
        $this->ticket('Badrul');
        $this->scan($in);
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00', 'Asia/Kuala_Lumpur'));

        // Tuesday: Aina came Monday, but nobody is in yet today.
        $this->actingAs($this->host)->get("/host/events/{$this->event->slug}/attendees")
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.daily', true)
                ->where('stats.checked_in', 0)
                ->where('tickets.data.0.status', 'valid')
                ->where('tickets.data.1.status', 'valid'));

        $this->scan($in);
        $this->actingAs($this->host)->get("/host/events/{$this->event->slug}/attendees?status=checked_in")
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.name', 'Aina'));
    }

    public function test_attendance_shows_who_came_on_which_day_and_exports_it(): void
    {
        $aina = $this->ticket('Aina');
        $badrul = $this->ticket('Badrul');
        $this->scan($aina);
        $this->scan($badrul);
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:15', 'Asia/Kuala_Lumpur'));
        $this->scan($aina);

        $this->actingAs($this->host)->get("/host/events/{$this->event->slug}/attendance")
            ->assertInertia(fn (Assert $page) => $page
                ->component('host/events/attendance')
                ->where('admissions', 2)
                ->has('days', 5) // Mon–Fri
                ->where('days.0.present', 2)
                ->where('days.1.present', 1)
                ->where('rows.data.0.name', 'Aina')
                ->where('rows.data.0.attended', 2)
                ->where('rows.data.0.days.2026-11-17', '10:15 AM'));

        // Who didn't come on Tuesday.
        $this->actingAs($this->host)->get("/host/events/{$this->event->slug}/attendance?day=2026-11-17&presence=absent")
            ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.name', 'Badrul'));

        $csv = $this->actingAs($this->host)->get("/host/events/{$this->event->slug}/attendance/export")->streamedContent();
        $this->assertStringContainsString('"Mon 16 Nov 2026","Tue 17 Nov 2026"', $csv);
        $this->assertMatchesRegularExpression('/Aina,aina@example.test,.*,"9:00 AM","10:15 AM",,,,2/', $csv);

        $log = $this->actingAs($this->host)->get("/host/events/{$this->event->slug}/attendance/export?format=log")->streamedContent();
        $this->assertSame(4, substr_count(trim($log), "\n") + 1, 'a header and three scans');
    }

    public function test_eight_hundred_people_page_and_export_without_trouble(): void
    {
        $order = Order::create(['reference' => 'DRSVP-BULK', 'event_id' => $this->event->id, 'status' => 'paid', 'total' => 0, 'paid_at' => now(), 'buyer_name' => 'Volunteers', 'buyer_email' => 'v@example.test']);
        $rows = [];
        for ($i = 1; $i <= 800; $i++) {
            $rows[] = ['order_id' => $order->id, 'event_id' => $this->event->id, 'attendee_name' => sprintf('Volunteer %03d', $i), 'status' => 'valid', 'qr_token' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Ticket::insert($chunk);
        }

        // A scan costs the same fixed number of queries however big the event
        // is (the answer, the row and the counters) — nothing per attendee.
        $t = Ticket::where('event_id', $this->event->id)->first();
        DB::enableQueryLog();
        $this->scan($t)->assertJson(['result' => 'ok']);
        $this->assertLessThan(40, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $this->actingAs($this->host)->get("/host/events/{$this->event->slug}/attendance")
            ->assertInertia(fn (Assert $page) => $page->where('admissions', 800)->has('rows.data', 50)->where('rows.total', 800));

        $csv = $this->actingAs($this->host)->get("/host/events/{$this->event->slug}/attendance/export")->streamedContent();
        $this->assertSame(801, substr_count(trim($csv), "\n") + 1);
    }

    public function test_the_organizer_turns_it_on_in_the_event_form(): void
    {
        $this->actingAs($this->host)->post(route('host.events.store'), [
            'title' => 'Volunteer Drive', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'daily_checkin' => true,
            'ticketTypes' => [['name' => 'Volunteer', 'kind' => 'free', 'price' => '0', 'min_per_order' => 1, 'max_per_order' => 1, 'is_active' => true]],
            'sessions' => [], 'gallery' => [], 'sections' => [],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Event::where('title', 'Volunteer Drive')->value('daily_checkin'));
    }

    public function test_someone_elses_attendance_is_off_limits(): void
    {
        $this->actingAs($this->organizer())->get("/host/events/{$this->event->slug}/attendance")->assertForbidden();
        $this->actingAs($this->organizer())->get("/host/events/{$this->event->slug}/attendance/export")->assertForbidden();
    }
}
