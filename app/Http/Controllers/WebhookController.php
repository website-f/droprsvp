<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    /** HitPay payment webhook — the source of truth for settlement. */
    public function hitpay(Request $request, PaymentGateway $gateway, CheckoutService $checkout): Response
    {
        $parsed = $gateway->parseWebhook($request);

        if ($parsed === null) {
            return response('invalid signature', 400);
        }

        if (! empty($parsed['reference']) && $parsed['paid']) {
            $order = Order::where('reference', $parsed['reference'])->first();
            if ($order) {
                $checkout->markPaid($order, (string) $request->input('payment_id'));
            }
        }

        return response('ok', 200);
    }
}
