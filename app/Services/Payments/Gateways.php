<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Which gateway an order is paid through. Malaysian (ringgit) orders go to
 * the app's default gateway — CHIP, or the fake one in development — and
 * orders recorded for Airwallex go there. The choice is made once, when the
 * order is opened (CountryRates), and stored on it, so a settings change
 * mid-checkout never sends one order to two gateways.
 */
class Gateways
{
    public function forOrder(Order $order): PaymentGateway
    {
        return $order->gateway === 'airwallex'
            ? app(AirwallexGateway::class)
            : app(PaymentGateway::class);
    }
}
