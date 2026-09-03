<?php

namespace Tests\Feature;

use App\Mail\WaitlistInvite;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    private function soldOutEvent(?User $host = null): Event
    {
        $event = Event::create([
            'user_id' => ($host ?? $this->organizer())->id, 'title' => 'Hot Show', 'slug' => 'hot-show-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDays(3),
        ]);
        TicketType::create(['event_id' => $event->id, 'name' => 'GA', 'price' => 30, 'currency' => 'MYR', 'quantity' => 10, 'sold' => 10, 'is_active' => true]);

        return $event;
    }

    public function test_a_visitor_can_join_the_waitlist(): void
    {
        $event = $this->soldOutEvent();

        $this->post("/e/{$event->slug}/waitlist", ['name' => 'Aisha', 'email' => 'aisha@example.test'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('waitlist_entries', ['event_id' => $event->id, 'email' => 'aisha@example.test', 'status' => 'waiting']);
    }

    public function test_joining_twice_with_the_same_email_does_not_duplicate(): void
    {
        $event = $this->soldOutEvent();

        $this->post("/e/{$event->slug}/waitlist", ['name' => 'Aisha', 'email' => 'a@example.test']);
        $this->post("/e/{$event->slug}/waitlist", ['name' => 'Aisha R', 'email' => 'a@example.test']);

        $this->assertSame(1, WaitlistEntry::where('event_id', $event->id)->count());
        $this->assertSame('Aisha R', WaitlistEntry::where('event_id', $event->id)->first()->name);
    }

    public function test_the_event_page_reports_sold_out(): void
    {
        $event = $this->soldOutEvent();

        $this->get("/en-my/e/{$event->slug}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('event.sold_out', true));
    }

    public function test_the_organizer_can_invite_a_waitlisted_person(): void
    {
        Mail::fake();
        $host = $this->organizer();
        $event = $this->soldOutEvent($host);
        $entry = WaitlistEntry::create(['event_id' => $event->id, 'name' => 'Bo', 'email' => 'bo@example.test', 'status' => 'waiting']);

        $this->actingAs($host)->post("/host/events/{$event->slug}/waitlist/{$entry->id}/notify")
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('notified', $entry->fresh()->status);
        $this->assertNotNull($entry->fresh()->notified_at);
        Mail::assertSent(WaitlistInvite::class, fn ($m) => $m->hasTo('bo@example.test'));
    }

    public function test_invite_everyone_waiting_only_touches_waiting_entries(): void
    {
        Mail::fake();
        $host = $this->organizer();
        $event = $this->soldOutEvent($host);
        WaitlistEntry::create(['event_id' => $event->id, 'name' => 'A', 'email' => 'a@example.test', 'status' => 'waiting']);
        WaitlistEntry::create(['event_id' => $event->id, 'name' => 'B', 'email' => 'b@example.test', 'status' => 'notified', 'notified_at' => now()]);

        $this->actingAs($host)->post("/host/events/{$event->slug}/waitlist/notify-all")->assertRedirect();

        Mail::assertSent(WaitlistInvite::class, 1);
        $this->assertSame(2, WaitlistEntry::where('event_id', $event->id)->where('status', 'notified')->count());
    }

    public function test_a_non_owner_cannot_view_the_waitlist(): void
    {
        $event = $this->soldOutEvent();

        $this->actingAs($this->organizer())->get("/host/events/{$event->slug}/waitlist")->assertForbidden();
    }
}
