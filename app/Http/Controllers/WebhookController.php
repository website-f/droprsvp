<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Promotion;
use App\Models\Subscription;
use App\Services\CheckoutService;
use App\Services\MembershipService;
use App\Services\Payments\PaymentGateway;
use App\Services\PromotionService;
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

    /** HitPay webhook for event boost/promotion payments. */
    public function promotions(Request $request, PaymentGateway $gateway, PromotionService $promotions): Response
    {
        $parsed = $gateway->parseWebhook($request);

        if ($parsed === null) {
            return response('invalid signature', 400);
        }

        if (! empty($parsed['reference']) && $parsed['paid']) {
            $promo = Promotion::where('reference', $parsed['reference'])->first();
            if ($promo) {
                $promotions->settle($promo, (string) $request->input('payment_id'));
            }
        }

        return response('ok', 200);
    }

    /** HitPay webhook for premium membership payments. */
    public function subscriptions(Request $request, PaymentGateway $gateway, MembershipService $membership): Response
    {
        $parsed = $gateway->parseWebhook($request);

        if ($parsed === null) {
            return response('invalid signature', 400);
        }

        if (! empty($parsed['reference']) && $parsed['paid']) {
            $sub = Subscription::where('reference', $parsed['reference'])->first();
            if ($sub) {
                $membership->settle($sub, (string) $request->input('payment_id'));
            }
        }

        return response('ok', 200);
    }
}
