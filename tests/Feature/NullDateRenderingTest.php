<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guards against the optional($date)->setTimezone()->format() crash class: pages
 * that format event/ticket dates must not 500 when those dates are null (real data
 * has date-less events and tickets checked in without a timestamp).
 */
class NullDateRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_pages_render_with_null_event_and_ticket_dates(): void
    {
        $host = $this->organizer();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Dateless', 'slug' => 'dateless-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => null, // no date
        ]);
        $order = Order::create(['reference' => 'DRSVP-ND', 'event_id' => $event->id, 'status' => 'paid', 'total' => 0, 'currency' => 'MYR', 'buyer_name' => 'Guest', 'paid_at' => null]);
        // A checked-in ticket with NO check-in timestamp — the exact crash trigger.
        $order->tickets()->create(['event_id' => $event->id, 'status' => 'checked_in', 'attendee_name' => 'Guest', 'checked_in_at' => null]);

        // Admin analytics (iterates events incl. the date-less one).
        $this->actingAs($this->superadmin())->get('/admin/analytics')->assertOk();

        // Host dashboard + analytics + door check-in.
        $this->actingAs($host)->get('/dashboard')->assertOk();
        $this->actingAs($host)->get('/host/analytics')->assertOk();
        $this->actingAs($host)->get(route('host.events.checkin', $event))->assertOk();

        // Public + discovery pages that render the "when".
        $this->get("/en-my/e/{$event->slug}")->assertOk();
        $this->get('/en-my/all')->assertOk();
    }
}
