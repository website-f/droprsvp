<?php

namespace App\Services;

use App\Models\Order;
use App\Support\Tracking;
use Illuminate\Support\Facades\Http;

/**
 * Purchases and refunds, reported to GA4 from the server.
 *
 * The browser-side `purchase` event only fired when the buyer's browser came
 * back to the confirmation page, with the tag loaded, AFTER the payment had
 * settled. Plenty of real sales did none of that: FPX and DuitNow buyers who
 * finished in their banking app and never returned to the tab; buyers who came
 * back before CHIP had settled and saw "Payment processing"; ad blockers. Each
 * was a sale in the database and nothing in GA.
 *
 * So the sale is reported where it is known for certain — when the order is
 * marked paid, whichever path did it — through the Measurement Protocol, using
 * the visitor's own GA client and session ids captured at checkout, so it is
 * still credited to their visit and its traffic source.
 *
 * Reporting must never get in the way of a sale: every failure is logged and
 * swallowed.
 */
class GoogleAnalytics
{
    private const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    public function purchase(Order $order): void
    {
        $this->send($order, 'purchase', Tracking::payload($order));
    }

    public function refund(Order $order, float $amount): void
    {
        $this->send($order, 'refund', [
            'transaction_id' => $order->reference,
            'value' => round($amount, 2),
            'currency' => $order->currency ?: 'MYR',
        ]);
    }

    private function send(Order $order, string $name, array $params): void
    {
        if (! Tracking::serverSide()) {
            return;
        }

        $ids = (array) ($order->meta['ga'] ?? []);

        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        // Without an engagement time GA does not count the hit towards an
        // active session; with the session id it joins the visit it came from.
        $params['engagement_time_msec'] = 1;
        if (! empty($ids['session_id'])) {
            $params['session_id'] = (string) $ids['session_id'];
        }

        try {
            Http::timeout(5)->asJson()->post(self::ENDPOINT.'?'.http_build_query([
                'measurement_id' => Tracking::measurementId(),
                'api_secret' => Tracking::apiSecret(),
            ]), [
                'client_id' => $ids['client_id'] ?? $this->fallbackClientId($order),
                'events' => [['name' => $name, 'params' => $params]],
            ])->throw();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * A stable stand-in for a buyer whose browser never had GA (ad blocker,
     * tag not loaded). Derived from the order, so its purchase and any later
     * refund are at least attributed to the same "user".
     */
    private function fallbackClientId(Order $order): string
    {
        return sprintf('%u', crc32($order->reference)).'.'.($order->created_at?->timestamp ?? time());
    }
}
