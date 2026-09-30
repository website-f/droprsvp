<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * What Google Analytics is allowed to see.
 *
 * The tag was emitted on every page of the app, so the property was recording
 * the BACK OFFICE: "Users - DropRSVP", the admin user list, an organizer's own
 * event page, a staff member opening a profile. That is not audience data, it
 * is us using the tool, and it drowns the handful of real public sessions the
 * site actually gets — the top pages report was entirely internal screens.
 *
 * So the tag now renders only on the pages a visitor can reach: the
 * marketplace, event and organizer pages, the blog, help, contact, and the
 * checkout funnel (which has to stay in, because that is where the purchase
 * event comes from).
 *
 * Everything an account holder does to RUN the platform — the admin panel, the
 * host panel, their dashboard, settings, their own tickets and invoices, and
 * the auth screens — is excluded.
 */
class GoogleAnalytics
{
    /**
     * Paths that are the product's back office rather than its shopfront.
     *
     * Matched with Request::is(), so '*' is a wildcard segment.
     */
    private const PRIVATE_PATHS = [
        'admin', 'admin/*',
        'host', 'host/*',
        'dashboard',
        'settings', 'settings/*',
        'my/*',
        'notifications', 'notifications/*',
        'following',
        'profile/*',
        'login', 'register', 'signup', 'get-started',
        'forgot-password', 'reset-password/*', 'set-password',
        'confirm-password', 'two-factor-challenge', 'verify-email', 'verify-email/*',
        'auth/*',
        'uploads',
        'become-a-vendor',
        'premium', 'premium/*',
    ];

    /** The GA4 measurement id, or null when analytics is switched off. */
    public static function measurementId(): ?string
    {
        $id = trim((string) config('services.ga.measurement_id'));

        return $id === '' ? null : $id;
    }

    /**
     * Should this request carry the tag?
     *
     * Local and testing never do — dev traffic and the test suite must not
     * appear in the property at all.
     */
    public static function shouldTrack(Request $request): bool
    {
        if (app()->environment('local', 'testing') || self::measurementId() === null) {
            return false;
        }

        return ! $request->is(...self::PRIVATE_PATHS);
    }

    /**
     * The GA4 `purchase` payload for a settled order, or null when there is
     * nothing to report.
     *
     * Why this exists at all: the property showed RM0 revenue and zero
     * purchasers while tickets were genuinely selling, because nothing ever
     * sent an ecommerce event — only page views. GA cannot infer a sale from a
     * page view, so the "Drive sales" reports had no data to draw.
     *
     * Built server-side from the order rather than assembled in the browser, so
     * the figures reported are the figures recorded, and a buyer with an ad
     * blocker simply drops the event instead of sending a wrong one.
     *
     * `value` is what the buyer actually paid (the order total, refunds aside),
     * because that is the number GA's revenue reports should agree with.
     *
     * @return array<string, mixed>|null
     */
    public static function purchasePayload(Order $order): ?array
    {
        return $order->status === 'paid' ? self::payload($order) : null;
    }

    /**
     * The GA4 `begin_checkout` payload for a pending order.
     *
     * Same shape as the purchase event on purpose: the checkout-journey report
     * compares the two, and a funnel whose first step is a different set of
     * items cannot line up.
     *
     * @return array<string, mixed>|null
     */
    public static function checkoutPayload(Order $order): ?array
    {
        return $order->status === 'pending' ? self::payload($order) : null;
    }

    /** @return array<string, mixed> */
    private static function payload(Order $order): array
    {
        $order->loadMissing(['items', 'event.category', 'discountCode']);

        return [
            // The order reference, so GA de-duplicates a refreshed confirmation
            // page into one transaction rather than counting it twice.
            'transaction_id' => $order->reference,
            'value' => round((float) $order->total, 2),
            'tax' => round((float) $order->tax, 2),
            'currency' => $order->currency ?: 'MYR',
            'coupon' => $order->discountCode?->code,
            'items' => $order->items->map(fn ($item) => array_filter([
                'item_id' => (string) ($item->ticket_type_id ?? $item->id),
                'item_name' => $item->name,
                'item_category' => $order->event?->category?->name,
                'item_brand' => $order->event?->title,
                'price' => round((float) $item->unit_price, 2),
                'quantity' => (int) $item->quantity,
            ], fn ($v) => $v !== null && $v !== ''))->values()->all(),
        ];
    }
}
