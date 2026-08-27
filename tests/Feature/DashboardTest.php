<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_aggregates_the_hosts_sales(): void
    {
        $host = User::factory()->create();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Dash Event', 'slug' => 'dash-event',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(3),
        ]);
        $order = Order::create([
            'reference' => 'DRSVP-DASH01', 'event_id' => $event->id, 'status' => 'paid',
            'buyer_name' => 'Ali', 'total' => 90, 'paid_at' => now(),
        ]);
        $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => 'Ali', 'status' => 'valid']);
        $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => 'Abu', 'status' => 'checked_in']);

        $this->actingAs($host)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('stats.events', 1)
                ->where('stats.tickets_sold', 2)
                ->where('stats.checked_in', 1)
                ->where('stats.revenue', 90)
                ->has('sales_by_day', 14)
                ->has('upcoming', 1)
                ->has('recent_orders', 1)
            );
    }

    public function test_new_host_sees_an_empty_dashboard(): void
    {
        $host = User::factory()->create();

        $this->actingAs($host)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('stats.events', 0));
    }

    public function test_dashboard_only_counts_your_own_events(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create();
        $otherEvent = Event::create([
            'user_id' => $other->id, 'title' => 'Not Mine', 'slug' => 'not-mine',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
        Order::create(['reference' => 'DRSVP-X', 'event_id' => $otherEvent->id, 'status' => 'paid', 'total' => 50, 'paid_at' => now()]);

        $this->actingAs($host)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('stats.events', 0)->where('stats.revenue', 0));
    }
}
