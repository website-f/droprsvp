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

class PlatformFeeTest extends TestCase
{
    use RefreshDatabase;

    private function order(float $price, int $qty = 1)
    {
        $event = Event::create(['user_id' => $this->organizer()->id, 'title' => 'E', 'slug' => 'e-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay()]);
        $tt = TicketType::create(['event_id' => $event->id, 'name' => 'GA', 'price' => $price, 'currency' => 'MYR', 'quantity' => 100, 'sold' => 0, 'is_active' => true, 'kind' => $price > 0 ? 'paid' : 'free']);

        return app(CheckoutService::class)->start($event, [['ticket_type_id' => $tt->id, 'quantity' => $qty]]);
    }

    public function test_the_fee_is_the_higher_of_percent_or_flat(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);

        // RM29 ticket → 5% = RM1.45, so the RM3 flat wins. Buyer pays 29 + 3 = 32.
        $cheap = $this->order(29);
        $this->assertEquals(3, (float) $cheap->fees);
        $this->assertEquals(32, (float) $cheap->total);

        // RM200 ticket → 5% = RM10 beats the RM3 flat. Buyer pays 200 + 10 = 210.
        $pricey = $this->order(200);
        $this->assertEquals(10, (float) $pricey->fees);
        $this->assertEquals(210, (float) $pricey->total);
    }

    public function test_free_tickets_are_never_charged_a_fee(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);

        $free = $this->order(0);
        $this->assertEquals(0, (float) $free->fees);
        $this->assertEquals(0, (float) $free->total);
    }

    public function test_the_fee_appears_on_the_buyers_receipt(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);
        $buyer = User::factory()->create(['email' => 'b@example.test']);
        $order = $this->order(100);
        $order->update(['status' => 'paid', 'user_id' => $buyer->id, 'buyer_email' => 'b@example.test', 'paid_at' => now()]);

        $this->actingAs($buyer)->get("/my/orders/{$order->reference}/receipt")->assertOk()
            ->assertInertia(fn ($p) => $p->where('receipt.fees', 5)->where('receipt.total', 105));
    }

    public function test_the_booking_fee_is_non_refundable(): void
    {
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);
        $order = $this->order(100); // total 105, fees 5
        $order->update(['status' => 'paid', 'paid_at' => now()]);

        // Only the RM100 ticket portion is refundable — the RM5 fee is kept.
        $this->assertEquals(100, $order->fresh()->remainingRefundable());
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
