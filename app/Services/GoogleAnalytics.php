<?php

namespace App\Services;

use App\Models\Order;
use App\Support\Tracking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Every sale reported to GA4 exactly once — by the buyer's browser when it can,
 * by the server when it can't.
 *
 * The browser on the confirmation page is the better reporter: it is the
 * visitor's own session, with its traffic source, in real time. But it misses
 * sales: an ad blocker or Safari's tracking protection drops the hit, a buyer
 * who finishes in their banking app never comes back to the tab, one who
 * closes the tab in the first seconds leaves before GA's batched hit goes out.
 *
 * So both report, and a ledger on the order keeps them from counting the same
 * sale twice:
 *
 *   1. The confirmation page waits for GA to actually load, then CLAIMS the
 *      order (atomic: only one claim can win), sends `purchase`, and ACKs once
 *      GA has dispatched it — ga_reported_via = 'browser'.
 *   2. Every few minutes the server SYNCS: any paid order with no report, and
 *      no live claim, is sent through the Measurement Protocol with the
 *      visitor's GA client/session ids and the original payment time —
 *      ga_reported_via = 'server'.
 *
 * A claim that is never acked (tab closed before GA dispatched, hit blocked
 * mid-flight) lapses after CLAIM_MINUTES and the server takes over. Both paths
 * use the same transaction_id and client_id, which GA also de-duplicates on, so
 * even that rare overlap does not double the revenue.
 */
class GoogleAnalytics
{
    private const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    private const DEBUG_ENDPOINT = 'https://www.google-analytics.com/debug/mp/collect';

    /** How long a browser claim holds before the server may report the order. */
    public const CLAIM_MINUTES = 10;

    /** How long after payment the browser gets first go, before the server steps in. */
    public const GRACE_MINUTES = 5;

    /** The Measurement Protocol only accepts events up to 72 hours old. */
    public const MAX_AGE_HOURS = 72;

    /** Paid orders GA has not heard about yet (refunded ones still were sales). */
    public static function unreported(): Builder
    {
        return Order::query()
            ->whereNotNull('paid_at')
            ->whereIn('status', ['paid', 'refunded'])
            ->whereNull('ga_reported_at');
    }

    /**
     * What GA should be showing vs what it has been sent, for a window — so a
     * superadmin can tally GA's purchase report against the books.
     *
     * Counted by payment time. Revenue is the amount paid (refunds aside), the
     * same `value` that was reported.
     *
     * @return array<string, mixed>
     */
    public static function tally(array $w): array
    {
        $paid = Order::query()->whereNotNull('paid_at')->whereIn('status', ['paid', 'refunded'])
            ->whereBetween('paid_at', [$w['from'], $w['to']]);

        $row = fn (Builder $q) => [
            'orders' => (int) (clone $q)->count(),
            'revenue' => round((float) (clone $q)->sum('total'), 2),
        ];

        $stale = now()->subHours(self::MAX_AGE_HOURS);

        return [
            'configured' => Tracking::serverSide(),
            'last_sync' => Cache::get('ga.sync.last'),
            'paid' => $row(clone $paid),
            'browser' => $row((clone $paid)->where('ga_reported_via', 'browser')),
            'server' => $row((clone $paid)->where('ga_reported_via', 'server')),
            // Not sent yet, and still sendable — the next sync will pick these up.
            'pending' => $row((clone $paid)->whereNull('ga_reported_at')->where('paid_at', '>', $stale)),
            // Too old for GA to accept with their real date (before the sync
            // existed, or while it was not configured).
            'missed' => $row((clone $paid)->whereNull('ga_reported_at')->where('paid_at', '<=', $stale)),
        ];
    }

    /**
     * Should the confirmation page send this sale from the browser?
     *
     * Not once it has been reported, and not when it is too old for GA to
     * place on the right day — the server has had it by then anyway.
     */
    public static function browserMaySend(Order $order): bool
    {
        return $order->status === 'paid'
            && $order->ga_reported_at === null
            && $order->paid_at !== null
            && $order->paid_at->gt(now()->subHours(self::MAX_AGE_HOURS));
    }

    /** The browser asks to report this sale. True if it won the claim. */
    public function claim(Order $order): bool
    {
        if (! self::browserMaySend($order)) {
            return false;
        }

        // One UPDATE, so two tabs (or a tab and the sync) cannot both win.
        return Order::whereKey($order->id)
            ->whereNull('ga_reported_at')
            ->where(fn ($q) => $q->whereNull('ga_claimed_at')
                ->orWhere('ga_claimed_at', '<', now()->subMinutes(self::CLAIM_MINUTES)))
            ->update(['ga_claimed_at' => now()]) === 1;
    }

    /** GA has dispatched the browser's hit. */
    public function ack(Order $order): void
    {
        Order::whereKey($order->id)
            ->whereNull('ga_reported_at')
            ->whereNotNull('ga_claimed_at')
            ->update(['ga_reported_at' => now(), 'ga_reported_via' => 'browser']);
    }

    /**
     * Report every sale the browser did not, then any refunds GA has not seen.
     *
     * @return array{sent: int, failed: int, skipped: int, configured: bool}
     */
    public function sync(bool $dryRun = false): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'configured' => Tracking::serverSide()];

        if (! Tracking::serverSide()) {
            return $summary;
        }

        $due = self::unreported()
            ->where('paid_at', '>', now()->subHours(self::MAX_AGE_HOURS))
            ->where('paid_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->where(fn ($q) => $q->whereNull('ga_claimed_at')
                ->orWhere('ga_claimed_at', '<', now()->subMinutes(self::CLAIM_MINUTES)))
            ->with(['items', 'event.category', 'discountCode'])
            ->orderBy('paid_at')
            ->limit(200)
            ->get();

        foreach ($due as $order) {
            if ($dryRun) {
                $summary['skipped']++;

                continue;
            }

            // Take it the same way the browser does, so a page that claims it
            // this very second cannot also send it.
            $won = Order::whereKey($order->id)->whereNull('ga_reported_at')
                ->where(fn ($q) => $q->whereNull('ga_claimed_at')
                    ->orWhere('ga_claimed_at', '<', now()->subMinutes(self::CLAIM_MINUTES)))
                ->update(['ga_claimed_at' => now()]) === 1;

            if (! $won) {
                $summary['skipped']++;

                continue;
            }

            $params = Tracking::payload($order);
            // The original moment of sale, so it lands on the right day.
            $ok = $this->send($order, 'purchase', $params, $order->paid_at);

            // Query updates, not model saves: the claim above was written by a
            // query too, so the model still thinks ga_claimed_at is null and
            // would treat "set it back to null" as no change at all.
            if ($ok) {
                Order::whereKey($order->id)->update(['ga_reported_at' => now(), 'ga_reported_via' => 'server']);
                $summary['sent']++;
            } else {
                // Release the claim so the next run retries.
                Order::whereKey($order->id)->update(['ga_claimed_at' => null]);
                $summary['failed']++;
            }
        }

        // Refunds on sales GA already has, not yet passed on.
        Order::query()->whereNotNull('ga_reported_at')->where('refunded_amount', '>', 0)
            ->where('updated_at', '>', now()->subHours(self::MAX_AGE_HOURS))
            ->limit(200)->get()
            ->each(function (Order $o) use ($dryRun) {
                if (! $dryRun) {
                    $this->refundChanged($o);
                }
            });

        if (! $dryRun) {
            Cache::forever('ga.sync.last', ['at' => now()->toIso8601String()] + $summary);
        }

        return $summary;
    }

    /**
     * Pass on whatever has been refunded since GA last heard, if GA has the sale.
     * A refund on a sale not yet reported waits: sync sends the purchase first,
     * then this, so GA never sees money go back on a sale it never saw.
     */
    public function refundChanged(Order $order): void
    {
        if (! Tracking::serverSide() || $order->ga_reported_at === null) {
            return;
        }

        $reported = (float) ($order->meta['ga_refunded'] ?? 0);
        $delta = round((float) $order->refunded_amount - $reported, 2);

        if ($delta <= 0) {
            return;
        }

        $ok = $this->send($order, 'refund', [
            'transaction_id' => $order->reference,
            'value' => $delta,
            'currency' => $order->currency ?: 'MYR',
        ]);

        if ($ok) {
            $order->forceFill(['meta' => [...($order->meta ?? []), 'ga_refunded' => (float) $order->refunded_amount]])->save();
        }
    }

    /**
     * Ask GA's validation server whether a sale WOULD be accepted, for setup
     * checks (`php artisan analytics:sync-purchases --validate`).
     *
     * @return array<int, mixed> GA's validation messages; empty means valid.
     */
    public function validate(Order $order): array
    {
        $response = Http::timeout(10)->asJson()->post(self::DEBUG_ENDPOINT.'?'.$this->query(), $this->body($order, 'purchase', Tracking::payload($order), $order->paid_at));

        return (array) ($response->json('validationMessages') ?? []);
    }

    private function send(Order $order, string $name, array $params, ?\DateTimeInterface $at = null): bool
    {
        try {
            return Http::timeout(8)->asJson()
                ->post(self::ENDPOINT.'?'.$this->query(), $this->body($order, $name, $params, $at))
                ->successful();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function query(): string
    {
        return http_build_query([
            'measurement_id' => Tracking::measurementId(),
            'api_secret' => Tracking::apiSecret(),
        ]);
    }

    /** @return array<string, mixed> */
    private function body(Order $order, string $name, array $params, ?\DateTimeInterface $at): array
    {
        $ids = (array) ($order->meta['ga'] ?? []);

        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        // Without an engagement time GA does not count the hit towards a
        // session; with the session id it joins the visit it came from.
        $params['engagement_time_msec'] = 1;
        if (! empty($ids['session_id'])) {
            $params['session_id'] = (string) $ids['session_id'];
        }

        return array_filter([
            'client_id' => $ids['client_id'] ?? $this->fallbackClientId($order),
            'timestamp_micros' => $at ? (int) ($at->getTimestamp() * 1_000_000) : null,
            'events' => [['name' => $name, 'params' => $params]],
        ], fn ($v) => $v !== null);
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
