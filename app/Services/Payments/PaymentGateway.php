<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * A payment provider. Swappable (HitPay in production, a fake driver in dev/test)
 * so the checkout flow never depends on a concrete gateway.
 */
interface PaymentGateway
{
    /**
     * Create a payment for the order and return the URL to send the buyer to.
     * Implementations should store their own reference on $order->payment_ref.
     */
    public function createCheckout(Order $order): string;

    /**
     * Verify + parse an incoming webhook. Returns
     * ['reference' => <order reference>, 'paid' => bool] or null if invalid.
     */
    public function parseWebhook(Request $request): ?array;

    /** Refund a settled order at the gateway. Returns true on success. */
    public function refund(Order $order): bool;
}
