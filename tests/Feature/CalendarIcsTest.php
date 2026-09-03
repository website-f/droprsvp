<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\Ics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarIcsTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'user_id' => $this->organizer()->id, 'title' => 'Jazz Night', 'slug' => 'jazz-night',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(4)->setTime(20, 0), 'ends_at' => now()->addDays(4)->setTime(23, 0),
            'venue_name' => 'The Blue Room', 'city' => 'Kuala Lumpur',
        ], $overrides));
    }

    public function test_a_published_event_downloads_as_an_ics_file(): void
    {
        $event = $this->event();

        $res = $this->get("/en-my/e/{$event->slug}/calendar.ics");

        $res->assertOk();
        $res->assertHeader('content-type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('attachment; filename="jazz-night.ics"', $res->headers->get('content-disposition'));

        $body = $res->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('SUMMARY:Jazz Night', $body);
        $this->assertStringContainsString('DTSTART:', $body);
        $this->assertStringContainsString('The Blue Room', $body);
    }

    public function test_a_draft_event_has_no_ics(): void
    {
        $event = $this->event(['status' => 'draft', 'slug' => 'secret-draft']);

        $this->get("/en-my/e/{$event->slug}/calendar.ics")->assertNotFound();
    }

    public function test_the_google_calendar_url_carries_the_event_details(): void
    {
        $event = $this->event(['slug' => 'gcal-check']);

        $url = Ics::googleUrl($event);

        $this->assertStringStartsWith('https://calendar.google.com/calendar/render?', $url);
        $this->assertStringContainsString('action=TEMPLATE', $url);
        $this->assertStringContainsString(urlencode('Jazz Night'), $url);
        $this->assertStringContainsString('dates=', $url);
    }
}
