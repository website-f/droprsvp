<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Dev/test gateway — no real API. `createCheckout` points the buyer at an internal
 * page that instantly settles the order, so the whole checkout flow can be
 * exercised end-to-end without CHIP credentials.
 */
class FakePaymentGateway implements PaymentGateway
{
    public function createCheckout(Order $order): string
    {
        $order->update(['payment_ref' => 'FAKE-'.$order->reference]);

        return route('checkout.fake', $order);
    }

    public function parseWebhook(Request $request): ?array
    {
        return null; // the fake gateway settles via its return page, not webhooks
    }

    public function refund(Order $order, ?float $amount = null): bool
    {
        return true; // no real money moved in dev
    }
}
