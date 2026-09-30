<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The promo-code journey end to end: an organizer creates a code while building
 * the event, the event page advertises it if they chose to, and a buyer lands
 * on checkout with it already applied.
 *
 * DiscountCodeTest already covers the redemption rules themselves (windows,
 * caps, minimum spend). This is about the two ends that were missing: getting a
 * code created without leaving the builder, and letting a buyer find one.
 */
class DiscountFlowTest extends TestCase
{
    use RefreshDatabase;

    private function host(): User
    {
        return $this->organizer(['name' => 'BoardLah Entertainment', 'slug' => 'boardlah']);
    }

    private function event(User $host): Event
    {
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        $event = Event::create([
            'user_id' => $host->id, 'category_id' => $category->id,
            'title' => 'Clocktower Night', 'slug' => 'clocktower-night',
            'description' => 'Social deduction.', 'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'city' => 'Kajang', 'venue_name' => 'HOL Cafe',
            'starts_at' => now()->addDays(14), 'published_at' => now(),
        ]);

        TicketType::create(['event_id' => $event->id, 'name' => 'Standard', 'kind' => 'paid', 'price' => 50]);

        return $event;
    }

    /** The builder payload, with whatever discount rows the test cares about. */
    private function builderPayload(array $discounts, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Clocktower Night',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'sessions' => [['starts_at' => now()->addDays(14)->format('Y-m-d H:i:s')]],
            'ticketTypes' => [['name' => 'Standard', 'kind' => 'paid', 'price' => 50, 'min_per_order' => 1, 'max_per_order' => 10]],
            'discounts' => $discounts,
        ], $overrides);
    }

    // ---- organizer: create codes while building the event ------------------

    public function test_a_code_can_be_created_with_the_event_itself(): void
    {
        $host = $this->host();

        $this->actingAs($host)->post(route('host.events.store'), $this->builderPayload([
            ['code' => 'earlybird', 'kind' => 'percent', 'value' => 20, 'is_active' => true, 'is_public' => true],
        ]))->assertSessionHasNoErrors();

        $code = DiscountCode::first();

        $this->assertNotNull($code, 'The builder should have created the code alongside the event.');
        $this->assertSame('EARLYBIRD', $code->code, 'Codes are stored upper-case so entry is case-insensitive.');
        $this->assertSame('percent', $code->kind);
        $this->assertTrue($code->is_public);
    }

    public function test_the_builder_refuses_two_codes_with_the_same_name(): void
    {
        $host = $this->host();

        $this->actingAs($host)->post(route('host.events.store'), $this->builderPayload([
            ['code' => 'DOUBLE', 'kind' => 'percent', 'value' => 10],
            ['code' => 'double', 'kind' => 'fixed', 'value' => 5],
        ]))->assertSessionHasErrors('discounts.1.code');

        $this->assertSame(0, DiscountCode::count());
    }

    public function test_a_percentage_over_one_hundred_is_refused(): void
    {
        $host = $this->host();

        $this->actingAs($host)->post(route('host.events.store'), $this->builderPayload([
            ['code' => 'FREEMONEY', 'kind' => 'percent', 'value' => 150],
        ]))->assertSessionHasErrors('discounts.0.value');
    }

    public function test_removing_a_redeemed_code_switches_it_off_instead_of_deleting_it(): void
    {
        $host = $this->host();
        $event = $this->event($host);

        $used = $event->discountCodes()->create(['code' => 'USED', 'kind' => 'percent', 'value' => 10, 'redemptions' => 3]);
        $unused = $event->discountCodes()->create(['code' => 'UNUSED', 'kind' => 'percent', 'value' => 10]);

        // Save the event with neither code in the payload.
        $this->actingAs($host)->put(route('host.events.update', $event), $this->builderPayload([]))
            ->assertSessionHasNoErrors();

        // The unused one is gone; the redeemed one survives, switched off, so
        // the orders that used it still point at something.
        $this->assertNull(DiscountCode::find($unused->id));
        $used->refresh();
        $this->assertFalse($used->is_active);
        $this->assertFalse($used->is_public);
    }

    // ---- buyer: discovering the code ---------------------------------------

    public function test_a_public_code_is_advertised_on_the_event_page(): void
    {
        $event = $this->event($this->host());
        $event->discountCodes()->create([
            'code' => 'EARLYBIRD', 'kind' => 'percent', 'value' => 20,
            'min_subtotal' => 30, 'is_active' => true, 'is_public' => true,
        ]);

        $this->get('/en-my/e/'.$event->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('offers', 1)
                ->where('offers.0.code', 'EARLYBIRD')
                ->where('offers.0.label', '20% off')
                // Numeric compare: a whole-ringgit minimum serialises without
                // its zero fraction, so a strict === against 30.0 fails on type.
                ->where('offers.0.min_subtotal', fn ($v) => (float) $v === 30.0));
    }

    public function test_private_expired_and_used_up_codes_are_never_advertised(): void
    {
        $event = $this->event($this->host());

        $event->discountCodes()->create(['code' => 'PRIVATE', 'kind' => 'percent', 'value' => 10, 'is_public' => false]);
        $event->discountCodes()->create(['code' => 'PAUSED', 'kind' => 'percent', 'value' => 10, 'is_public' => true, 'is_active' => false]);
        $event->discountCodes()->create(['code' => 'EXPIRED', 'kind' => 'percent', 'value' => 10, 'is_public' => true, 'ends_at' => now()->subDay()]);
        $event->discountCodes()->create(['code' => 'NOTYET', 'kind' => 'percent', 'value' => 10, 'is_public' => true, 'starts_at' => now()->addWeek()]);
        $event->discountCodes()->create(['code' => 'GONE', 'kind' => 'percent', 'value' => 10, 'is_public' => true, 'max_redemptions' => 5, 'redemptions' => 5]);

        $this->get('/en-my/e/'.$event->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('offers', 0))
            // And the private one must not leak into the payload in any form.
            ->assertDontSee('PRIVATE', false);
    }

    // ---- buyer: using it ---------------------------------------------------

    public function test_a_code_tapped_on_the_event_page_is_already_applied_at_checkout(): void
    {
        $event = $this->event($this->host());
        $type = $event->ticketTypes()->first();
        $event->discountCodes()->create(['code' => 'EARLYBIRD', 'kind' => 'percent', 'value' => 20, 'is_public' => true]);

        $this->post(route('checkout.start', $event), [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
            'code' => 'EARLYBIRD',
        ])->assertRedirect();

        $order = Order::latest('id')->first();

        $this->assertSame(100.0, (float) $order->subtotal);
        $this->assertSame(20.0, (float) $order->discount, '20% of RM100.');
        $this->assertSame(80.0, (float) $order->total);
    }

    public function test_a_code_that_does_not_apply_explains_itself_without_losing_the_order(): void
    {
        $event = $this->event($this->host());
        $type = $event->ticketTypes()->first();
        $event->discountCodes()->create([
            'code' => 'BIGSPEND', 'kind' => 'percent', 'value' => 20, 'is_public' => true, 'min_subtotal' => 500,
        ]);

        $this->post(route('checkout.start', $event), [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'code' => 'BIGSPEND',
        ])->assertRedirect()->assertSessionHas('flash_warning');

        $order = Order::latest('id')->first();

        // The order still exists at full price — the buyer is on checkout with
        // an explanation, not staring at a lost cart.
        $this->assertNotNull($order);
        $this->assertSame(0.0, (float) $order->discount);
        $this->assertSame(50.0, (float) $order->total);
    }

    public function test_the_redemption_is_only_counted_once_the_order_settles(): void
    {
        $event = $this->event($this->host());
        $type = $event->ticketTypes()->first();
        $code = $event->discountCodes()->create(['code' => 'EARLYBIRD', 'kind' => 'fixed', 'value' => 10, 'is_public' => true]);

        $this->post(route('checkout.start', $event), [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'code' => 'EARLYBIRD',
        ]);

        $order = Order::latest('id')->first();
        $this->assertSame(0, $code->fresh()->redemptions, 'An unpaid order must not burn a redemption.');

        $order->update(['buyer_name' => 'Azfar', 'buyer_email' => 'azfar@example.com']);
        app(CheckoutService::class)->markPaid($order, 'TEST');

        $this->assertSame(1, $code->fresh()->redemptions);
        $this->assertSame(40.0, (float) $order->fresh()->total);
    }
}
