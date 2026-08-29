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

    private function publishedEvent(?User $host = null): Event
    {
        $host ??= User::factory()->create();

        return Event::create([
            'user_id' => $host->id, 'title' => 'Analytics Event', 'slug' => 'analytics-event',
            'status' => 'published', 'published_at' => now(), 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(10),
        ]);
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

    public function test_superadmin_can_drill_into_one_event(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $event = $this->publishedEvent();

        $this->actingAs($admin)->get('/admin/analytics?event='.$event->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/analytics')
                ->where('selectedSlug', $event->slug)
                ->where('selected.event.slug', $event->slug)
                ->has('selected.kpis')
                ->has('selected.trend', 30));
    }
}
