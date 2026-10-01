<?php

namespace App\Support;

use App\Models\Order;
use App\Support\Edm\Consent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who started buying and did not finish — for the superadmin's analytics and
 * for follow-up (EDM) campaigns.
 *
 * Every tap on "Checkout" creates a `pending` order that holds the tickets, so
 * the orders table already is the cart log; nothing extra is recorded. An order
 * moves through:
 *
 *   started  — tickets picked, checkout opened (no buyer details yet);
 *   details  — the buyer filled in and submitted the form (name, email, phone,
 *              their answers, consent) and was sent on to payment;
 *   paid     — paid_at is set.
 *
 * An order is ABANDONED when it never got paid_at and is no longer in its hold
 * window: either `orders:release-stale` has released it (status `cancelled`,
 * paid_at null — a cancelled free RSVP keeps its paid_at, so it is not counted),
 * or it is still `pending` but older than the hold. Orders inside the hold are
 * "in progress": the buyer may be on the payment page right now.
 *
 * An abandoned order is RECOVERED when the same person (same email, or the
 * same account) went on to pay for the same event. Those are kept out of the
 * contact list by default — nobody wants a "you left something behind" email
 * for tickets they already hold.
 */
class CheckoutFunnel
{
    /** Same window `orders:release-stale` uses before releasing a hold. */
    public const HOLD_MINUTES = 30;

    public const STAGE_LABELS = [
        'started' => 'Picked tickets, left at checkout',
        'details' => 'Filled in details, didn’t pay',
    ];

    /** Unpaid orders that have left their hold window. */
    public static function abandoned(): Builder
    {
        return Order::query()
            ->whereNull('paid_at')
            ->whereIn('status', ['pending', 'cancelled', 'failed'])
            ->where(fn (Builder $q) => $q
                ->where('status', '!=', 'pending')
                ->orWhere('created_at', '<', now()->subMinutes(self::HOLD_MINUTES)));
    }

    /**
     * Headline numbers + the step-by-step funnel for a window, platform-wide or
     * for one event. Counts are by order created in the window.
     *
     * @return array<string,mixed>
     */
    public static function summary(array $w, ?int $eventId = null, int $impressions = 0): array
    {
        $scope = fn (Builder $q) => $q
            ->whereBetween('created_at', [$w['from'], $w['to']])
            ->when($eventId !== null, fn ($x) => $x->where('event_id', $eventId));

        $started = $scope(Order::query())->count();
        $details = $scope(Order::query())->whereNotNull('buyer_email')->count();
        $paid = $scope(Order::query())->whereNotNull('paid_at')->count();
        $inProgress = $scope(Order::query())->where('status', 'pending')->whereNull('paid_at')
            ->where('created_at', '>=', now()->subMinutes(self::HOLD_MINUTES))->count();

        $rows = self::rows($w, $eventId);
        $open = $rows->where('recovered', false);

        return [
            'funnel' => [
                ['key' => 'views', 'label' => 'Event page views', 'value' => $impressions],
                ['key' => 'started', 'label' => 'Checkout started', 'value' => $started],
                ['key' => 'details', 'label' => 'Filled in details', 'value' => $details],
                ['key' => 'paid', 'label' => 'Paid / confirmed', 'value' => $paid],
            ],
            'started' => $started,
            'details' => $details,
            'paid' => $paid,
            'in_progress' => $inProgress,
            'checkout_conversion' => $started > 0 ? round($paid / $started * 100, 1) : 0.0,
            'abandonment_rate' => $started > 0 ? round(($started - $paid - $inProgress) / $started * 100, 1) : 0.0,
            'abandoned' => [
                'orders' => $rows->sum('attempts'),
                'started' => $rows->where('stage', 'started')->sum('attempts'),
                'details' => $rows->where('stage', 'details')->sum('attempts'),
                'recovered' => $rows->where('recovered', true)->count(),
                // People, not orders: one person retrying three times is one
                // follow-up, and one lost sale.
                'people' => $open->count(),
                'reachable' => $open->filter(fn ($r) => $r['email'] !== null)->count(),
                'anonymous' => $open->filter(fn ($r) => $r['email'] === null)->sum('attempts'),
                'lost_value' => round((float) $open->sum('value'), 2),
                'tickets' => (int) $open->sum('tickets'),
            ],
        ];
    }

    /**
     * Abandoned checkouts, one row per person per event.
     *
     * A person is their email (as typed at checkout, else their account's), or
     * — for a guest who left before typing anything — the order itself, since
     * there is nothing to tie two such orders together. Their furthest stage,
     * latest attempt and largest basket are what a follow-up needs.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public static function rows(array $w, ?int $eventId = null): Collection
    {
        $orders = self::abandoned()
            ->whereBetween('created_at', [$w['from'], $w['to']])
            ->when($eventId !== null, fn ($x) => $x->where('event_id', $eventId))
            ->with([
                'event:id,title,slug,starts_at,timezone,city,user_id',
                'user:id,name,email,phone',
                'items:id,order_id,name,quantity,line_total',
            ])
            ->latest()
            ->limit(5000)
            ->get();

        if ($orders->isEmpty()) {
            return collect();
        }

        $recovered = self::recoveredKeys($orders);

        return $orders
            ->groupBy(fn (Order $o) => $o->event_id.'|'.(self::emailOf($o) ?? 'order:'.$o->id))
            ->map(function (Collection $group) use ($recovered) {
                /** @var Order $latest */
                $latest = $group->first();
                $email = self::emailOf($latest) ?? $group->map(fn ($o) => self::emailOf($o))->filter()->first();
                $withDetails = $group->first(fn (Order $o) => $o->buyer_email !== null);
                $source = $withDetails ?? $latest;
                $biggest = $group->sortByDesc(fn (Order $o) => (float) $o->total)->first();
                $userId = $group->pluck('user_id')->filter()->first();
                $event = $latest->event;

                return [
                    'key' => $latest->id,
                    'event_id' => $latest->event_id,
                    'event' => $event?->title ?? '—',
                    'event_slug' => $event?->slug,
                    'event_date' => $event?->starts_at?->setTimezone($event->timezone ?: Dates::tz())->format('j M Y'),
                    'stage' => $withDetails ? 'details' : 'started',
                    'name' => $source->buyer_name ?: $latest->user?->name,
                    'email' => $email,
                    'phone' => $source->buyer_phone ?: $latest->user?->phone,
                    'city' => $source->buyer_city,
                    'gender' => $source->buyer_gender,
                    'age_band' => $source->buyer_age_band,
                    'source' => $source->buyer_source,
                    'account' => $userId !== null,
                    // How they relate to us: agreed to the RSVP terms on the
                    // checkout form, or a signed-up member. NOT permission to
                    // market — that switch is required to buy at all. Marketing
                    // consent is `marketing` below, from email_consents.
                    'consent' => $withDetails ? 'checkout' : ($userId ? 'account' : null),
                    'tickets' => (int) $biggest->items->sum('quantity'),
                    'items' => $biggest->items->map(fn ($i) => $i->quantity.' × '.$i->name)->implode(', '),
                    'value' => round((float) $biggest->total, 2),
                    'currency' => $biggest->currency ?: 'MYR',
                    'attempts' => $group->count(),
                    'reference' => $latest->reference,
                    'last_at' => $latest->created_at?->toIso8601String(),
                    'last_at_label' => $latest->created_at?->setTimezone(Dates::tz())->format('j M Y, g:ia'),
                    'recovered' => self::isRecovered($recovered, $latest->event_id, $email, $userId),
                ];
            })
            ->sortByDesc('last_at')
            ->values()
            ->pipe(function (Collection $rows) {
                // One query for the whole list rather than one per row.
                $subscribed = Consent::subscribedMap($rows->pluck('email'));

                return $rows->map(fn (array $r) => [
                    ...$r,
                    'marketing' => (bool) ($subscribed[Consent::normalise($r['email'])] ?? false),
                ]);
            });
    }

    /** Abandoned vs paid per day, for the trend chart. */
    public static function trend(array $w, ?int $eventId = null): array
    {
        $paid = Order::query()->whereNotNull('paid_at')
            ->whereBetween('created_at', [$w['from'], $w['to']])
            ->when($eventId !== null, fn ($x) => $x->where('event_id', $eventId))
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');
        $lost = self::abandoned()
            ->whereBetween('created_at', [$w['from'], $w['to']])
            ->when($eventId !== null, fn ($x) => $x->where('event_id', $eventId))
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');

        return Analytics::bucketed($w, fn ($d) => [
            'paid' => (int) ($paid[$d->toDateString()] ?? 0),
            'abandoned' => (int) ($lost[$d->toDateString()] ?? 0),
        ], ['paid' => 0, 'abandoned' => 0]);
    }

    /** Events losing the most checkouts in the window. */
    public static function byEvent(Collection $rows, int $limit = 10): array
    {
        return $rows->groupBy('event_id')
            ->map(fn (Collection $g) => [
                'event' => $g->first()['event'],
                'slug' => $g->first()['event_slug'],
                'people' => $g->where('recovered', false)->count(),
                'details' => $g->where('recovered', false)->where('stage', 'details')->count(),
                'recovered' => $g->where('recovered', true)->count(),
                'lost_value' => round((float) $g->where('recovered', false)->sum('value'), 2),
            ])
            ->sortByDesc('people')
            ->take($limit)
            ->values()
            ->all();
    }

    /** The email a follow-up would go to: as typed at checkout, else the account's. */
    private static function emailOf(Order $order): ?string
    {
        $email = $order->buyer_email ?: $order->user?->email;

        return $email ? mb_strtolower(trim($email)) : null;
    }

    /**
     * Paid orders for the same events, as "event|email" and "event|u:id" keys.
     *
     * @return array<string,true>
     */
    private static function recoveredKeys(Collection $orders): array
    {
        $paid = Order::query()
            ->whereIn('event_id', $orders->pluck('event_id')->unique()->values())
            ->whereNotNull('paid_at')
            ->with('user:id,email')
            ->get(['id', 'event_id', 'user_id', 'buyer_email']);

        $keys = [];
        foreach ($paid as $p) {
            if ($email = self::emailOf($p)) {
                $keys[$p->event_id.'|'.$email] = true;
            }
            if ($p->user_id) {
                $keys[$p->event_id.'|u:'.$p->user_id] = true;
            }
        }

        return $keys;
    }

    private static function isRecovered(array $keys, int $eventId, ?string $email, ?int $userId): bool
    {
        return ($email !== null && isset($keys[$eventId.'|'.$email]))
            || ($userId !== null && isset($keys[$eventId.'|u:'.$userId]));
    }
}
