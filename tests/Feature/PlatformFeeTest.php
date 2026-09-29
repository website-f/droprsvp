<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\PlatformFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The platform commission is charged to the ORGANIZER, not the buyer: a buyer
 * pays the ticket price plus any tax and nothing else. The fee is recorded on
 * the order and withheld from the organizer's payout instead.
 */
class PlatformFeeTest extends TestCase
{
    use RefreshDatabase;

    private function order(float $price, int $qty = 1)
    {
        $event = Event::create(['user_id' => $this->organizer()->id, 'title' => 'E', 'slug' => 'e-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay()]);
        $tt = TicketType::create(['event_id' => $event->id, 'name' => 'GA', 'price' => $price, 'currency' => 'MYR', 'quantity' => 100, 'sold' => 0, 'is_active' => true, 'kind' => $price > 0 ? 'paid' : 'free']);

        return app(CheckoutService::class)->start($event, [['ticket_type_id' => $tt->id, 'quantity' => $qty]]);
    }

    public function test_the_fee_is_the_higher_of_percent_or_flat_and_never_reaches_the_buyer(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);

        // RM29 ticket → 5% = RM1.45, so the RM3 flat wins. The buyer still pays
        // exactly RM29; the RM3 is withheld from the organizer.
        $cheap = $this->order(29);
        $this->assertEquals(3, (float) $cheap->fees);
        $this->assertEquals(29, (float) $cheap->total);

        // RM200 ticket → 5% = RM10 beats the RM3 flat. Buyer pays RM200.
        $pricey = $this->order(200);
        $this->assertEquals(10, (float) $pricey->fees);
        $this->assertEquals(200, (float) $pricey->total);
    }

    public function test_the_organizer_keeps_the_price_minus_the_commission(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);

        $order = $this->order(200);

        // PayoutService settles `total - fees`: RM200 paid, RM10 commission.
        $this->assertEquals(190, (float) $order->total - (float) $order->fees);
    }

    public function test_free_tickets_are_never_charged_a_fee(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);

        $free = $this->order(0);
        $this->assertEquals(0, (float) $free->fees);
        $this->assertEquals(0, (float) $free->total);
    }

    public function test_the_buyers_receipt_totals_the_ticket_price_only(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);
        $buyer = User::factory()->create(['email' => 'b@example.test']);
        $order = $this->order(100);
        $order->update(['status' => 'paid', 'user_id' => $buyer->id, 'buyer_email' => 'b@example.test', 'paid_at' => now()]);

        // The RM5 commission is recorded, but the buyer was charged RM100.
        $this->actingAs($buyer)->get("/my/orders/{$order->reference}/receipt")->assertOk()
            ->assertInertia(fn ($p) => $p->where('receipt.total', 100));
    }

    public function test_everything_the_buyer_paid_is_refundable(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);
        $order = $this->order(100); // buyer paid 100, commission 5
        $order->update(['status' => 'paid', 'paid_at' => now()]);

        // The commission never touched the buyer's card, so withholding it from
        // their refund would short them on money they never paid.
        $this->assertEquals(100, $order->fresh()->remainingRefundable());
    }

    public function test_a_full_refund_voids_the_commission_so_the_organizer_is_not_left_negative(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);
        $order = $this->order(100);
        $order->update(['status' => 'paid', 'paid_at' => now()]);

        app(CheckoutService::class)->refund($order, null, null);

        $order->refresh();

        $this->assertEquals(0, (float) $order->fees);
        $this->assertEquals(100, (float) $order->refunded_amount);
        // PayoutService settles `total - fees - refunded_amount`.
        $this->assertEquals(0, (float) $order->total - (float) $order->fees - (float) $order->refunded_amount);
    }

    public function test_the_minimum_ticket_price_is_the_flat_fee(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);

        // Below RM3 the flat commission exceeds the ticket price and the
        // organizer would be paid a negative amount.
        $this->assertSame(3.0, PlatformFee::minimumTicketPrice());
        $this->assertSame(-1.0, PlatformFee::organizerNet(2));
        $this->assertSame(0.0, PlatformFee::organizerNet(3));
        $this->assertSame(7.0, PlatformFee::organizerNet(10));
    }

    public function test_fee_helper_math(): void
    {
        Config::set('droprsvp.platform_fee_percent', 10);
        Config::set('droprsvp.platform_fee_flat', 2);

        $this->assertSame(10.0, PlatformFee::on(100)); // 10% wins
        $this->assertSame(2.0, PlatformFee::on(15));   // flat wins (10% = 1.5)
        $this->assertSame(0.0, PlatformFee::on(0));    // free
    }
}
