<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** A gateway that records every refund amount it's asked to move. */
class RecordingGateway implements PaymentGateway
{
    /** @var array<int, float|null> */
    public static array $refunds = [];

    public function createCheckout(Order $order): string
    {
        return 'https://pay.test';
    }

    public function parseWebhook(Request $request): ?array
    {
        return null;
    }

    public function refund(Order $order, ?float $amount = null): bool
    {
        self::$refunds[] = $amount;

        return true;
    }
}

class RefundIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingGateway::$refunds = [];
        $this->app->bind(PaymentGateway::class, RecordingGateway::class);
    }

    private function paidOrder(User $host, float $total = 100): Order
    {
        $event = Event::create(['user_id' => $host->id, 'title' => 'E', 'slug' => 'e-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay()]);

        return Order::create(['reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'paid', 'total' => $total, 'currency' => 'MYR', 'paid_at' => now()]);
    }

    public function test_host_refund_only_moves_the_remaining_balance_after_a_partial(): void
    {
        $host = $this->organizer();
        $order = $this->paidOrder($host, 100);
        // A prior RM30 partial refund (local only).
        app(CheckoutService::class)->refund($order, 30);

        $this->actingAs($host)->post("/host/events/{$order->event->slug}/orders/{$order->reference}/refund")
            ->assertRedirect();

        // The gateway was asked to move only the remaining RM70 — never the full 100.
        $this->assertSame([70.0], RecordingGateway::$refunds);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertEquals(100, (float) $order->fresh()->refunded_amount); // capped at total
    }

    public function test_approving_the_same_request_twice_only_refunds_once(): void
    {
        $host = $this->organizer();
        $order = $this->paidOrder($host, 100);
        $req = RefundRequest::create(['order_id' => $order->id, 'amount' => 40, 'status' => 'pending']);

        $this->actingAs($host)->post("/host/refunds/{$req->id}/approve", ['amount' => 40])->assertRedirect();
        // Second approval of the now-decided request is rejected.
        $this->actingAs($host)->post("/host/refunds/{$req->id}/approve", ['amount' => 40])->assertStatus(422);

        $this->assertSame([40.0], RecordingGateway::$refunds); // gateway hit exactly once
        $this->assertEquals(40, (float) $order->fresh()->refunded_amount);
        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_a_gateway_rejection_records_no_local_refund(): void
    {
        // Swap in a gateway that refuses.
        $this->app->bind(PaymentGateway::class, fn () => new class implements PaymentGateway
        {
            public function createCheckout(Order $order): string
            {
                return '';
            }

            public function parseWebhook(Request $request): ?array
            {
                return null;
            }

            public function refund(Order $order, ?float $amount = null): bool
            {
                return false;
            }
        });

        $host = $this->organizer();
        $order = $this->paidOrder($host, 100);

        $this->actingAs($host)->post("/host/events/{$order->event->slug}/orders/{$order->reference}/refund")
            ->assertRedirect();

        // Gateway said no → nothing recorded locally, order still paid.
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertEquals(0, (float) $order->fresh()->refunded_amount);
    }
}
