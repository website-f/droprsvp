<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * What the third-party trackers are allowed to see.
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
class Tracking
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

    /**
     * The private paths as ONE JavaScript regular expression.
     *
     * The server refusing to render the tag is only half the job. Inertia is a
     * SPA: a visitor who lands on a public page has the tag loaded, and every
     * click after that is a history change, not a new document. GA4's enhanced
     * measurement turns each of those into a page_view on its own — which is
     * why /dashboard kept appearing in the reports even after the tag stopped
     * rendering on it. Nothing server-side can prevent that; the browser has
     * to be told to stop.
     *
     * Generated from PRIVATE_PATHS rather than hand-written, so the two can't
     * drift: one list decides, in both places.
     */
    public static function privatePathPattern(): string
    {
        $parts = [];

        foreach (self::PRIVATE_PATHS as $path) {
            // 'admin/*' -> 'admin/.*' ; 'admin' -> 'admin'
            $escaped = preg_quote(rtrim($path, '/*'), '/');
            $parts[] = str_ends_with($path, '/*') ? $escaped.'\\/.*' : $escaped;
        }

        // Anchored, and tolerant of a trailing slash on the bare forms.
        return '^\\/(?:'.implode('|', array_unique($parts)).')\\/?$';
    }

    /**
     * The staff side of the back office. These pages never carry even the
     * dormant config: an admin or organizer who lands here and then opens a
     * public page is not a visitor we need to count from that session.
     */
    private const STAFF_PATHS = ['admin', 'admin/*', 'host', 'host/*'];

    /**
     * Everything the browser needs to police itself, or null when tracking is off.
     *
     * On a public page the tags are already rendered and this is the rule for
     * muting them on the way into the back office (`active` true).
     *
     * On a BUYER's private page — login, their dashboard, their tickets — the
     * tags are not rendered, but the config is still handed over with
     * `active` false. This is a SPA: someone who signs in, browses to an event
     * and checks out never loads another document until the payment gateway,
     * so a session that started on /login was invisible to GA from end to
     * end — no page views, no begin_checkout. TrackingGuard loads the tags the
     * first time such a session reaches a public page; nothing is sent while
     * they stay on private ones.
     */
    public static function clientConfig(Request $request): ?array
    {
        if (app()->environment('local', 'testing') || $request->is(...self::STAFF_PATHS)) {
            return null;
        }

        $ga = self::measurementId();
        $clarity = self::clarityId();

        if ($ga === null && $clarity === null) {
            return null;
        }

        return [
            'ga' => $ga,
            'clarity' => $clarity,
            'private' => self::privatePathPattern(),
            'active' => self::shouldTrack($request),
        ];
    }

    /** The Measurement Protocol secret, or null when server-side reporting is off. */
    public static function apiSecret(): ?string
    {
        return self::id('services.ga.api_secret');
    }

    /**
     * Are purchases reported from the server?
     *
     * When they are, the confirmation page does NOT also send one from the
     * browser — two sources for one sale is how revenue gets double-counted.
     */
    public static function serverSide(): bool
    {
        return self::measurementId() !== null && self::apiSecret() !== null;
    }

    /**
     * The visitor's GA identity, read from the cookies gtag set.
     *
     * `client_id` comes from `_ga` ("GA1.1.<random>.<timestamp>") and
     * `session_id` from `_ga_<stream>` ("GS1.1.<session>.…", or the newer
     * "GS2.1.s<session>$o…"). Stored on the order at checkout so a purchase
     * reported later from the server — by the payment webhook, in no browser
     * at all — is credited to the visitor and the visit that made it, with its
     * traffic source, rather than to an anonymous new user.
     *
     * Read from the raw Cookie header: Laravel's cookie encryption would hand
     * back null for a cookie it did not write.
     *
     * @return array{client_id?: string, session_id?: string}
     */
    public static function gaIdsFrom(Request $request): array
    {
        $cookies = [];
        foreach (explode(';', (string) $request->headers->get('cookie', '')) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2) {
                $cookies[$parts[0]] = urldecode($parts[1]);
            }
        }

        $ids = [];

        $ga = explode('.', $cookies['_ga'] ?? '');
        if (count($ga) >= 4 && ctype_digit($ga[2]) && ctype_digit($ga[3])) {
            $ids['client_id'] = $ga[2].'.'.$ga[3];
        }

        $stream = self::measurementId() ? substr((string) self::measurementId(), 2) : null;
        $session = $stream ? ($cookies['_ga_'.$stream] ?? null) : null;
        if ($session !== null) {
            if (preg_match('/^GS1\.\d+\.(\d+)/', $session, $m) || preg_match('/(?:^|[.$])s(\d+)/', $session, $m)) {
                $ids['session_id'] = $m[1];
            }
        }

        return $ids;
    }

    /** The GA4 measurement id, or null when analytics is switched off. */
    public static function measurementId(): ?string
    {
        return self::id('services.ga.measurement_id');
    }

    /**
     * The Microsoft Clarity project id, or null when it is switched off.
     *
     * Clarity is scoped by exactly the same rule as GA, and for a stronger
     * reason: it records the session, so on an admin screen it would be
     * recording other people's names, email addresses and order history and
     * shipping them to a third party. It belongs on the shopfront only.
     */
    public static function clarityId(): ?string
    {
        return self::id('services.clarity.project_id');
    }

    private static function id(string $key): ?string
    {
        $id = trim((string) config($key));

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
        if (app()->environment('local', 'testing')) {
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
    public static function payload(Order $order): array
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
