<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function publishedEvent(?User $host = null, array $overrides = []): Event
    {
        $host ??= User::factory()->create();

        return Event::create(array_merge([
            'user_id' => $host->id, 'title' => 'Analytics Event', 'slug' => 'analytics-event',
            'status' => 'published', 'published_at' => now(), 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(10),
        ], $overrides));
    }

    public function test_viewing_a_public_event_records_an_impression(): void
    {
        $event = $this->publishedEvent();

        $this->get('/e/'.$event->slug)->assertOk();

        $this->assertDatabaseHas('event_daily_stats', ['event_id' => $event->id, 'impressions' => 1]);
    }

    public function test_the_organizer_previewing_their_own_event_does_not_count(): void
    {
        $host = $this->organizer();
        $event = $this->publishedEvent($host);

        $this->actingAs($host)->get('/e/'.$event->slug)->assertOk();

        $this->assertDatabaseMissing('event_daily_stats', ['event_id' => $event->id]);
    }

    public function test_starting_checkout_records_a_click_and_pay_stores_demographics(): void
    {
        $event = $this->publishedEvent();
        $tt = TicketType::create([
            'event_id' => $event->id, 'name' => 'Free', 'kind' => 'free', 'price' => 0,
            'min_per_order' => 1, 'max_per_order' => 4, 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->post("/e/{$event->slug}/checkout", ['items' => [['ticket_type_id' => $tt->id, 'quantity' => 1]]])
            ->assertRedirect();
        $this->assertDatabaseHas('event_daily_stats', ['event_id' => $event->id, 'clicks' => 1]);

        $order = Order::first();
        $this->post("/checkout/{$order->reference}/pay", [
            'buyer_name' => 'Jane Doe', 'buyer_email' => 'jane@example.com', 'buyer_phone' => '0123456789',
            'buyer_gender' => 'female', 'buyer_age_band' => '25-34', 'buyer_city' => 'Kuala Lumpur', 'buyer_source' => 'instagram',
            'consent' => true,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('female', $order->buyer_gender);
        $this->assertSame('25-34', $order->buyer_age_band);
        $this->assertSame('instagram', $order->buyer_source);
    }

    public function test_organizer_can_view_event_analytics(): void
    {
        $host = $this->organizer();
        $event = $this->publishedEvent($host);

        $this->actingAs($host)->get("/host/events/{$event->slug}/analytics")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('host/events/analytics')->has('trend', 30)->has('kpis'));
    }

    public function test_non_owner_cannot_view_event_analytics(): void
    {
        $event = $this->publishedEvent();

        $this->actingAs($this->organizer())->get("/host/events/{$event->slug}/analytics")->assertForbidden();
    }

    public function test_superadmin_can_view_platform_analytics(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/analytics')->has('reach', 30)->has('revenue', 30)->has('kpis'));
    }

    public function test_superadmin_can_open_one_events_analytics_page(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $event = $this->publishedEvent();

        $this->actingAs($admin)->get('/admin/analytics/'.$event->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/analytics/event')
                ->where('data.event.slug', $event->slug)
                ->has('data.kpis')
                ->has('data.trend', 30));
    }

    public function test_events_table_is_searchable_and_paginated(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->publishedEvent(null, ['title' => 'Jazz By The Bay', 'slug' => 'jazz-bay']);
        $this->publishedEvent(null, ['title' => 'Tech Summit', 'slug' => 'tech-summit']);

        $this->actingAs($admin)->get('/admin/analytics?q=Jazz')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/analytics')
                ->where('filters.q', 'Jazz')
                ->has('events.data', 1)
                ->where('events.data.0.slug', 'jazz-bay'));
    }

    public function test_period_filter_changes_the_reach_window(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->get('/admin/analytics?period=7d')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/analytics')
                ->has('reach', 7)
                ->has('revenue', 7)
                ->where('filters.period', '7d'));
    }

    public function test_custom_range_buckets_by_day(): void
    {
        $admin = $this->superadmin();
        $from = now()->subDays(9)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($admin)->get("/admin/analytics?period=custom&from={$from}&to={$to}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/analytics')
                ->has('reach', 10) // 10 inclusive days
                ->where('filters.period', 'custom'));
    }

    public function test_status_filter_narrows_the_events_table(): void
    {
        $admin = $this->superadmin();
        $this->publishedEvent(null, ['title' => 'Live One', 'slug' => 'live-one']);
        $this->publishedEvent(null, ['title' => 'Draft One', 'slug' => 'draft-one', 'status' => 'draft']);

        $this->actingAs($admin)->get('/admin/analytics?status=draft')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/analytics')
                ->where('filters.status', 'draft')
                ->has('events.data', 1)
                ->where('events.data.0.slug', 'draft-one'));
    }

    public function test_category_filter_narrows_the_events_table(): void
    {
        $admin = $this->superadmin();
        $cat = \App\Models\EventCategory::create(['name' => 'Music', 'slug' => 'music', 'sort_order' => 0]);
        $this->publishedEvent(null, ['title' => 'Cat One', 'slug' => 'cat-one', 'category_id' => $cat->id]);
        $this->publishedEvent(null, ['title' => 'No Cat', 'slug' => 'no-cat']);

        $this->actingAs($admin)->get('/admin/analytics?category='.$cat->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/analytics')
                ->where('filters.category', (string) $cat->id)
                ->has('events.data', 1)
                ->where('events.data.0.slug', 'cat-one'));
    }

    public function test_analytics_exposes_audience_filter_options_and_echoes_the_selection(): void
    {
        $admin = $this->superadmin();
        $event = $this->publishedEvent();
        \App\Models\Order::create([
            'reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'paid',
            'total' => 50, 'paid_at' => now(), 'buyer_city' => 'Kuala Lumpur', 'buyer_source' => 'instagram',
        ]);

        $this->actingAs($admin)->get('/admin/analytics?city=Kuala+Lumpur&source=instagram')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/analytics')
                ->where('filters.city', 'Kuala Lumpur')
                ->where('filters.source', 'instagram')
                ->has('cityOptions')
                ->has('sourceOptions'));
    }

    public function test_superadmin_can_export_events_analytics_as_csv(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->publishedEvent(null, ['title' => 'Export Me', 'slug' => 'export-me']);

        $res = $this->actingAs($admin)->get('/admin/analytics/export')->assertOk();
        $this->assertStringContainsString('Event,Status,Date', $res->streamedContent());
        $this->assertStringContainsString('Export Me', $res->streamedContent());
    }
}
