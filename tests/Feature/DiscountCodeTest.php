<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DiscountCodeTest extends TestCase
{
    use RefreshDatabase;

    private function event(?User $host = null): Event
    {
        return Event::create([
            'user_id' => ($host ?? $this->organizer())->id, 'title' => 'Show', 'slug' => 'show-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDays(3),
        ]);
    }

    private function pendingOrder(Event $event, User $buyer, float $subtotal = 100): Order
    {
        return Order::create([
            'reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'pending',
            'subtotal' => $subtotal, 'tax' => 0, 'total' => $subtotal, 'currency' => 'MYR', 'user_id' => $buyer->id,
        ]);
    }

    public function test_an_organizer_creates_a_promo_code(): void
    {
        $host = $this->organizer();
        $event = $this->event($host);

        $this->actingAs($host)->post("/host/events/{$event->slug}/discounts", [
            'code' => 'earlybird', 'kind' => 'percent', 'value' => 15, 'is_active' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('discount_codes', ['event_id' => $event->id, 'code' => 'EARLYBIRD', 'kind' => 'percent', 'value' => '15.00']);
    }

    public function test_a_non_owner_cannot_manage_codes(): void
    {
        $event = $this->event();

        $this->actingAs($this->organizer())->post("/host/events/{$event->slug}/discounts", [
            'code' => 'HACK', 'kind' => 'percent', 'value' => 50,
        ])->assertForbidden();
    }

    public function test_applying_a_percentage_code_recomputes_the_total(): void
    {
        $host = $this->organizer();
        $event = $this->event($host);
        DiscountCode::create(['event_id' => $event->id, 'code' => 'SAVE10', 'kind' => 'percent', 'value' => 10, 'is_active' => true]);
        $buyer = User::factory()->create();
        $order = $this->pendingOrder($event, $buyer, 100);

        $this->actingAs($buyer)->post("/checkout/{$order->reference}/code", ['code' => 'save10'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $order->refresh();
        $this->assertEquals(10, (float) $order->discount);
        $this->assertEquals(90, (float) $order->total);
        $this->assertNotNull($order->discount_code_id);
    }

    public function test_a_fixed_code_below_min_spend_is_rejected(): void
    {
        $event = $this->event();
        DiscountCode::create(['event_id' => $event->id, 'code' => 'BIG20', 'kind' => 'fixed', 'value' => 20, 'min_subtotal' => 200, 'is_active' => true]);
        $buyer = User::factory()->create();
        $order = $this->pendingOrder($event, $buyer, 100);

        $this->actingAs($buyer)->post("/checkout/{$order->reference}/code", ['code' => 'BIG20'])
            ->assertSessionHasErrors('code');

        $this->assertEquals(0, (float) $order->fresh()->discount);
    }

    public function test_an_inactive_code_is_rejected(): void
    {
        $event = $this->event();
        DiscountCode::create(['event_id' => $event->id, 'code' => 'OFF', 'kind' => 'percent', 'value' => 10, 'is_active' => false]);
        $buyer = User::factory()->create();
        $order = $this->pendingOrder($event, $buyer);

        $this->actingAs($buyer)->post("/checkout/{$order->reference}/code", ['code' => 'OFF'])->assertSessionHasErrors('code');
    }

    public function test_removing_a_code_restores_the_full_total(): void
    {
        $event = $this->event();
        DiscountCode::create(['event_id' => $event->id, 'code' => 'SAVE10', 'kind' => 'percent', 'value' => 10, 'is_active' => true]);
        $buyer = User::factory()->create();
        $order = $this->pendingOrder($event, $buyer, 100);

        $this->actingAs($buyer)->post("/checkout/{$order->reference}/code", ['code' => 'SAVE10']);
        $this->actingAs($buyer)->delete("/checkout/{$order->reference}/code")->assertRedirect();

        $order->refresh();
        $this->assertEquals(0, (float) $order->discount);
        $this->assertEquals(100, (float) $order->total);
        $this->assertNull($order->discount_code_id);
    }

    public function test_redemption_is_counted_only_when_the_order_settles(): void
    {
        $event = $this->event();
        $code = DiscountCode::create(['event_id' => $event->id, 'code' => 'SAVE10', 'kind' => 'percent', 'value' => 10, 'is_active' => true]);
        $buyer = User::factory()->create();
        $order = $this->pendingOrder($event, $buyer, 100);
        $order->update(['discount' => 10, 'total' => 90, 'discount_code_id' => $code->id]);

        $this->assertSame(0, (int) $code->fresh()->redemptions);

        app(CheckoutService::class)->markPaid($order);

        $this->assertSame(1, (int) $code->fresh()->redemptions);
    }

    public function test_the_discounts_page_shows_redemption_analytics(): void
    {
        $host = $this->organizer();
        $event = $this->event($host);
        $code = DiscountCode::create(['event_id' => $event->id, 'code' => 'SAVE10', 'kind' => 'percent', 'value' => 10, 'is_active' => true]);
        Order::create(['reference' => 'DRSVP-PAID1', 'event_id' => $event->id, 'status' => 'paid', 'subtotal' => 100, 'discount' => 10, 'total' => 90, 'currency' => 'MYR', 'discount_code_id' => $code->id, 'paid_at' => now()]);

        $this->actingAs($host)->get("/host/events/{$event->slug}/discounts")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('host/events/discounts')
                ->where('codes.0.stats.redemptions', 1)
                ->where('codes.0.stats.revenue', 90)
                ->where('codes.0.stats.discount_given', 10));
    }
}
