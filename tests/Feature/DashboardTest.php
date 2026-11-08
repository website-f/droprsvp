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
                // All time by default, which starts at the first sale (today).
                ->where('filters.period', 'all')
                ->has('sales_by_day', 1)
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

    public function test_the_numbers_follow_the_period_and_event_filters(): void
    {
        $host = User::factory()->create();
        $make = fn (string $slug) => Event::create([
            'user_id' => $host->id, 'title' => ucfirst($slug), 'slug' => $slug,
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(3),
        ]);
        $a = $make('night-a');
        $b = $make('night-b');

        Order::create(['reference' => 'DRSVP-OLD', 'event_id' => $a->id, 'status' => 'paid', 'total' => 100, 'paid_at' => now()->subMonths(2)]);
        Order::create(['reference' => 'DRSVP-NEW', 'event_id' => $a->id, 'status' => 'paid', 'total' => 40, 'refunded_amount' => 10, 'paid_at' => now()]);
        Order::create(['reference' => 'DRSVP-B', 'event_id' => $b->id, 'status' => 'paid', 'total' => 25, 'paid_at' => now()]);

        // All time: everything, net of refunds.
        $this->actingAs($host)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('stats.revenue', 155)
            ->where('stats.orders', 3)
            ->where('filters.periodLabel', 'All time'));

        // This week: the two sales from today.
        $this->actingAs($host)->get('/dashboard?period=week')->assertInertia(fn (Assert $page) => $page
            ->where('stats.revenue', 55)
            ->where('stats.orders', 2)
            ->where('filters.periodLabel', 'This week')
            ->has('recent_orders', 2));

        // One event, this month.
        $this->actingAs($host)->get('/dashboard?period=month&event=night-a')->assertInertia(fn (Assert $page) => $page
            ->where('stats.revenue', 30)
            ->where('stats.orders', 1)
            ->where('stats.average_order', 30)
            ->where('stats.events', 1)
            ->where('filters.event', 'night-a')
            ->has('eventOptions', 2));

        // A custom range around the older sale.
        $day = now()->subMonths(2)->toDateString();
        $this->actingAs($host)->get("/dashboard?period=custom&from={$day}&to={$day}")->assertInertia(fn (Assert $page) => $page
            ->where('stats.revenue', 100)
            ->has('sales_by_day', 1));
    }

    public function test_someone_elses_event_cannot_be_picked(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create();
        $theirs = Event::create([
            'user_id' => $other->id, 'title' => 'Not Mine', 'slug' => 'not-mine',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
        Order::create(['reference' => 'DRSVP-T', 'event_id' => $theirs->id, 'status' => 'paid', 'total' => 50, 'paid_at' => now()]);

        $this->actingAs($host)->get('/dashboard?event=not-mine')
            ->assertInertia(fn (Assert $page) => $page->where('stats.revenue', 0)->where('filters.event', ''));
    }
}
