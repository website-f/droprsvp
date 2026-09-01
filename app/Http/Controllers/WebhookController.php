<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payout;
use App\Models\Promotion;
use App\Models\Subscription;
use App\Services\CheckoutService;
use App\Services\MembershipService;
use App\Services\Payments\ChipSendGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\PayoutService;
use App\Services\PromotionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    /** CHIP payment webhook — the source of truth for ticket-order settlement. */
    public function chip(Request $request, PaymentGateway $gateway, CheckoutService $checkout): Response
    {
        $parsed = $gateway->parseWebhook($request);

        if ($parsed === null) {
            return response('invalid signature', 400);
        }

        if (! empty($parsed['reference']) && $parsed['paid']) {
            $order = Order::where('reference', $parsed['reference'])->first();
            if ($order) {
                $checkout->markPaid($order, $parsed['payment_ref'] ?? null);
            }
        }

        return response('ok', 200);
    }

    /** CHIP webhook for event boost/promotion payments. */
    public function promotions(Request $request, PaymentGateway $gateway, PromotionService $promotions): Response
    {
        $parsed = $gateway->parseWebhook($request);

        if ($parsed === null) {
            return response('invalid signature', 400);
        }

        if (! empty($parsed['reference']) && $parsed['paid']) {
            $promo = Promotion::where('reference', $parsed['reference'])->first();
            if ($promo) {
                $promotions->settle($promo, $parsed['payment_ref'] ?? null);
            }
        }

        return response('ok', 200);
    }

    /** CHIP webhook for premium membership payments. */
    public function subscriptions(Request $request, PaymentGateway $gateway, MembershipService $membership): Response
    {
        $parsed = $gateway->parseWebhook($request);

        if ($parsed === null) {
            return response('invalid signature', 400);
        }

        if (! empty($parsed['reference']) && $parsed['paid']) {
            $sub = Subscription::where('reference', $parsed['reference'])->first();
            if ($sub) {
                $membership->settle($sub, $parsed['payment_ref'] ?? null);
            }
        }

        return response('ok', 200);
    }

    /**
     * CHIP Send status webhook. We don't trust the payload — we re-fetch each
     * in-flight payout's status from the authenticated API, so a spoofed hit
     * can only trigger a harmless re-sync.
     */
    public function chipSend(Request $request, ChipSendGateway $send, PayoutService $payouts): Response
    {
        Payout::whereNotNull('chip_send_id')->whereIn('status', ['processing', 'pending'])->get()
            ->each(fn (Payout $p) => $payouts->syncChipStatus($p, $send));

        return response('ok', 200);
    }
}
