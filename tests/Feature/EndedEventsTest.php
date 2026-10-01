<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ended events leave the public listings — and nothing else.
 *
 * They are not deleted: their page stays up for anyone holding the link, the
 * organizer keeps their history, and attendees keep their receipts. They just
 * stop being offered on the home page, the browse pages and search.
 */
class EndedEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->organizer(['name' => 'BoardLah Entertainment', 'slug' => 'boardlah']);
        EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);
    }

    private function event(string $slug, ?string $starts, ?string $ends): Event
    {
        return Event::create([
            'user_id' => $this->host->id,
            'category_id' => EventCategory::first()->id,
            'title' => 'Show '.$slug, 'slug' => $slug,
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'city' => 'Kajang', 'venue_name' => 'HOL Cafe',
            'starts_at' => $starts ? Carbon::parse($starts, 'Asia/Kuala_Lumpur')->utc() : null,
            'ends_at' => $ends ? Carbon::parse($ends, 'Asia/Kuala_Lumpur')->utc() : null,
            'published_at' => now(),
        ]);
    }

    /** @return list<string> slugs the scope lets through */
    private function listed(): array
    {
        return Event::published()->notEnded()->orderBy('slug')->pluck('slug')->all();
    }

    public function test_the_rule_for_each_kind_of_event(): void
    {
        // 3pm on 10 Oct 2026, Malaysia time.
        Carbon::setTestNow(Carbon::parse('2026-10-10 15:00', 'Asia/Kuala_Lumpur'));

        $this->event('ended-yesterday', '2026-10-09 19:00', '2026-10-09 22:00');
        $this->event('ended-an-hour-ago', '2026-10-10 10:00', '2026-10-10 14:00');
        $this->event('on-now', '2026-10-10 14:00', '2026-10-10 18:00');
        $this->event('multi-day-still-running', '2026-10-08 10:00', '2026-10-12 18:00');
        $this->event('tomorrow', '2026-10-11 15:00', '2026-10-11 18:00');
        $this->event('start-only-this-morning', '2026-10-10 09:00', null);
        $this->event('start-only-yesterday', '2026-10-09 20:00', null);
        $this->event('undated', null, null);

        $this->assertSame([
            'multi-day-still-running', // started days ago, but it is not over
            'on-now',
            'start-only-this-morning', // no end time: listed for the rest of its day
            'tomorrow',
            'undated',
        ], $this->listed());
    }

    public function test_the_day_boundary_is_malaysian_midnight_not_utc(): void
    {
        // 7am MYT on the 11th is still 23:00 UTC on the 10th. An event with no
        // end time that started at 9pm MYT on the 10th must already be gone.
        Carbon::setTestNow(Carbon::parse('2026-10-11 07:00', 'Asia/Kuala_Lumpur'));

        $this->event('last-night', '2026-10-10 21:00', null);

        $this->assertSame([], $this->listed());
    }

    public function test_ended_events_leave_home_browse_and_search_but_keep_their_page(): void
    {
        $this->event('finished', now()->subDays(3)->format('Y-m-d H:i'), now()->subDays(3)->addHours(3)->format('Y-m-d H:i'));
        $this->event('coming-up', now()->addDays(3)->format('Y-m-d H:i'), now()->addDays(3)->addHours(3)->format('Y-m-d H:i'));

        // Browse.
        $browse = collect($this->get('/en-my/all/')->assertOk()->viewData('page')['props']['events']['data'])->pluck('slug');
        $this->assertContains('coming-up', $browse);
        $this->assertNotContains('finished', $browse);

        // Search suggestions — these had no date filter at all.
        $suggest = collect($this->getJson(route('search.suggest', ['q' => 'Show']))->assertOk()->json('events'))->pluck('label');
        $this->assertContains('Show coming-up', $suggest);
        $this->assertNotContains('Show finished', $suggest);

        // Home.
        $this->get('/en-my/')->assertOk()->assertDontSee('Show finished');

        // Not deleted, and its own page still works.
        $this->assertNotNull(Event::where('slug', 'finished')->first());
        $this->get('/en-my/e/finished')->assertOk();
    }

    public function test_has_ended_agrees_with_the_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 15:00', 'Asia/Kuala_Lumpur'));

        $this->assertTrue($this->event('a', '2026-10-10 10:00', '2026-10-10 14:00')->hasEnded());
        $this->assertFalse($this->event('b', '2026-10-08 10:00', '2026-10-12 18:00')->hasEnded());
        $this->assertFalse($this->event('c', '2026-10-10 09:00', null)->hasEnded());
        $this->assertTrue($this->event('d', '2026-10-09 20:00', null)->hasEnded());
        $this->assertFalse($this->event('e', null, null)->hasEnded());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
