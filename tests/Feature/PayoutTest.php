<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use RefreshDatabase;

    private function hostWithRevenue(float $gross): User
    {
        $host = $this->organizer();
        $event = Event::create(['user_id' => $host->id, 'title' => 'E', 'slug' => 'e-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        Order::create(['reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'paid', 'total' => $gross, 'paid_at' => now()]);

        return $host;
    }

    public function test_balance_applies_the_platform_fee(): void
    {
        Config::set('droprsvp.platform_fee_percent', 10);
        $host = $this->hostWithRevenue(100);

        $this->actingAs($host)->get(route('host.payouts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('host/payouts')
                ->where('balance.gross', 100)
                ->where('balance.fee', 10)
                ->where('balance.net', 90)
                ->where('balance.available', 90));
    }

    public function test_balance_applies_a_fixed_per_order_fee(): void
    {
        \App\Models\Setting::put('platform_fee_type', 'fixed');
        \App\Models\Setting::put('platform_fee_fixed', 2);
        $host = $this->organizer();
        $event = Event::create(['user_id' => $host->id, 'title' => 'E', 'slug' => 'e-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        // Two paid orders → RM2 each → RM4 fee, regardless of order value.
        Order::create(['reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'paid', 'total' => 100, 'paid_at' => now()]);
        Order::create(['reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'paid', 'total' => 50, 'paid_at' => now()]);

        $balance = app(\App\Services\PayoutService::class)->balanceFor($host);
        $this->assertSame(150.0, $balance['gross']);
        $this->assertSame(4.0, $balance['fee']);
        $this->assertSame(146.0, $balance['net']);
        $this->assertSame('fixed', $balance['fee_type']);
    }

    public function test_upcoming_event_revenue_is_held_until_the_event_ends(): void
    {
        Config::set('droprsvp.platform_fee_percent', 0);
        $host = $this->organizer();

        // Future event — funds not yet matured.
        $future = Event::create(['user_id' => $host->id, 'title' => 'Later', 'slug' => 'later-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDays(5)]);
        Order::create(['reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $future->id, 'status' => 'paid', 'total' => 100, 'paid_at' => now()]);

        $balance = app(\App\Services\PayoutService::class)->balanceFor($host);
        $this->assertSame(0.0, $balance['available']);
        $this->assertSame(100.0, $balance['pending_clearance']);

        // A past event's takings are available.
        $past = Event::create(['user_id' => $host->id, 'title' => 'Done', 'slug' => 'done-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        Order::create(['reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $past->id, 'status' => 'paid', 'total' => 40, 'paid_at' => now()]);

        $balance = app(\App\Services\PayoutService::class)->balanceFor($host);
        $this->assertSame(40.0, $balance['available']);
        $this->assertSame(100.0, $balance['pending_clearance']);
    }

    public function test_requesting_a_payout_reduces_available_to_zero(): void
    {
        Config::set('droprsvp.platform_fee_percent', 10);
        $host = $this->hostWithRevenue(100);

        $this->actingAs($host)->post(route('host.payouts.request'))->assertRedirect();

        $payout = Payout::first();
        $this->assertEquals(90, $payout->amount);
        $this->assertSame('pending', $payout->status);
        $this->assertSame($host->id, $payout->user_id);

        // No funds left to request again.
        $this->actingAs($host)->post(route('host.payouts.request'))->assertSessionHasErrors('payout');
        $this->assertSame(1, Payout::count());
    }

    public function test_superadmin_can_mark_a_payout_paid(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $host = $this->hostWithRevenue(50);
        $this->actingAs($host)->post(route('host.payouts.request'));
        $payout = Payout::first();

        $this->actingAs($admin)->post(route('admin.payouts.paid', $payout))->assertRedirect();
        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertNotNull($payout->fresh()->paid_at);
    }

    public function test_non_superadmin_cannot_open_admin_payouts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.payouts.index'))->assertForbidden();
    }
}
