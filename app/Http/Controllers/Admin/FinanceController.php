<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Support\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The money view: every movement through the platform, as double entry.
 *
 * One paid order is not one transaction. It is up to three, and collapsing them
 * into a single "ticket sale" row was why the platform's own revenue was
 * invisible here — you could see RM100 of tickets sold and nowhere the RM3 of it
 * that is ours. An order now contributes:
 *
 *   ticket  in   the gross the buyer paid
 *   fee     in   our commission out of that gross (the organizer's cost)
 *   refund  out  anything given back, at the amount actually refunded
 *
 * …alongside boosts and subscriptions (in) and organizer payouts (out).
 *
 * `ticket` and `fee` overlap on purpose — the fee is a slice of the ticket, not
 * money on top of it. So the tiles separate GMV (what we PROCESSED) from
 * platform revenue (what we KEPT), because summing the ledger blindly would
 * count the commission twice.
 */
class FinanceController extends Controller
{
    /** Every row type the ledger can produce. */
    private const TYPES = ['ticket', 'fee', 'boost', 'subscription', 'payout', 'refund'];

    /** How many options a filter dropdown carries before it says it is truncated. */
    private const OPTION_LIMIT = 500;

    /** Per-request cache of kpis(), keyed by the filter set. @var array<string,array> */
    private array $totals = [];

    public function index(Request $request)
    {
        $f = $this->filters($request);

        // The instrument behind a payment is superadmin-only: it is the closest
        // thing here to the buyer's payment details, and staff with finance
        // access do not need it to reconcile an amount.
        $showMethod = (bool) $request->user()?->hasRole('superadmin');

        return inertia('admin/finance', [
            'kpis' => $this->kpis($f),
            'trend' => $this->trend($f),
            'breakdown' => $this->breakdown($f),
            'transactions' => $this->ledger($f)->paginate(20)->withQueryString()
                ->through(fn ($t) => $this->row($t, $showMethod)),
            'filters' => $f,
            'options' => $this->options(),
            'showMethod' => $showMethod,
            'currency' => config('services.chip.currency', 'MYR'),
            'exportUrl' => route('admin.finance.export', array_filter($f, fn ($v) => $v !== '' && $v !== 'all')),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $showMethod = (bool) $request->user()?->hasRole('superadmin');
        $rows = $this->ledger($f)->get();
        $currency = config('services.chip.currency', 'MYR');

        $headings = ['Date', 'Type', 'Reference', 'Party', 'Direction', 'Amount', 'Currency', 'Status'];

        if ($showMethod) {
            $headings[] = 'Payment method';
            $headings[] = 'Bank / card';
        }

        return response()->streamDownload(function () use ($rows, $headings, $showMethod, $currency) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headings);

            foreach ($rows as $t) {
                $r = $this->row($t, $showMethod);
                $line = [$r['date'], $r['type'], $r['reference'], $r['party'], $r['direction'], $r['amount'], $currency, $r['status']];

                if ($showMethod) {
                    $line[] = $r['payment']['label'] ?? '';
                    $line[] = $r['payment']['brand_label'] ?? '';
                }

                fputcsv($out, $line);
            }

            fclose($out);
        }, 'droprsvp-finance-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{q:string,type:string,direction:string,event:string,organizer:string,method:string,from:string,to:string} */
    private function filters(Request $request): array
    {
        $one = function (string $key, array $allowed) use ($request): string {
            $value = (string) $request->query($key, '');

            return in_array($value, $allowed, true) ? $value : 'all';
        };

        return [
            'q' => trim((string) $request->query('q', '')),
            'type' => $one('type', self::TYPES),
            'direction' => $one('direction', ['in', 'out']),
            // Not validated against a list: these are ids, and an id that
            // matches nothing simply returns an empty ledger.
            'event' => (string) $request->query('event', '') ?: 'all',
            'organizer' => (string) $request->query('organizer', '') ?: 'all',
            'method' => $one('method', array_keys(PaymentMethod::methods())),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];
    }

    /**
     * The filter dropdowns, built FROM the ledger rather than from the events
     * and users tables.
     *
     * Two reasons. Every option is then guaranteed to return rows — no picking
     * a name and getting an empty table. And listing "all organizers" by role
     * meant asking Spatie for a role that throws if it has not been seeded,
     * which turned a missing role into a 500 on the whole finance page.
     *
     * Each option carries a hint (an event's date, an organizer's email) so two
     * events with the same title are still tellable apart in a list of
     * hundreds, and the lists are capped — the client says so when they are.
     */
    private function options(): array
    {
        $distinct = fn (string $column) => DB::query()->fromSub($this->allUnion(), 't')
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column);

        $events = Event::whereIn('id', $distinct('event_id'))
            ->orderByDesc('starts_at')
            ->limit(self::OPTION_LIMIT + 1)
            ->get(['id', 'title', 'starts_at', 'city']);

        $organizers = User::whereIn('id', $distinct('organizer_id'))
            ->orderBy('name')
            ->limit(self::OPTION_LIMIT + 1)
            ->get(['id', 'name', 'email']);

        return [
            'events' => $events->take(self::OPTION_LIMIT)->map(fn ($e) => [
                'value' => (string) $e->id,
                'label' => $e->title,
                'hint' => collect([$e->starts_at?->format('j M Y'), $e->city])->filter()->implode(' · ') ?: null,
            ])->values()->all(),

            'organizers' => $organizers->take(self::OPTION_LIMIT)->map(fn ($u) => [
                'value' => (string) $u->id,
                'label' => $u->name,
                'hint' => $u->email,
            ])->values()->all(),

            // Fetching one more than the cap is how we know there were more,
            // without a second COUNT over the same union.
            'eventsTruncated' => $events->count() > self::OPTION_LIMIT,
            'organizersTruncated' => $organizers->count() > self::OPTION_LIMIT,

            'methods' => collect(PaymentMethod::methods())
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values()->all(),

            'types' => collect(self::TYPES)
                ->map(fn ($t) => ['value' => $t, 'label' => ucfirst($t)])
                ->all(),
        ];
    }

    /**
     * Totals for the tiles.
     *
     * These honour the event, organizer and date filters but NOT type or
     * direction — narrowing to "payouts only" and then reading a "platform
     * revenue" tile of zero would be actively misleading. The page says so.
     */
    private function kpis(array $f): array
    {
        // Memoised: breakdown() wants the same numbers, and re-running a
        // six-branch UNION over every order on the platform to get them twice
        // is the kind of thing that only hurts once there is real data in it.
        //
        // An instance property, not a static: the controller is built fresh per
        // request, so this cannot outlive the data it summarises.
        $key = md5(serialize($f));

        if (isset($this->totals[$key])) {
            return $this->totals[$key];
        }

        $byType = DB::query()->fromSub($this->allUnion(), 't')
            ->tap(fn ($q) => $this->applyScope($q, $f))
            ->selectRaw('type, sum(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $sum = fn (string $type) => round((float) ($byType[$type] ?? 0), 2);

        $tickets = $sum('ticket');
        $fees = $sum('fee');
        $boosts = $sum('boost');
        $subscriptions = $sum('subscription');
        $refunds = $sum('refund');
        $payouts = $sum('payout');

        return $this->totals[$key] = [
            // What we processed on organizers' behalf. Not our money.
            'ticket_sales' => $tickets,
            // What we kept out of it. This IS our money.
            'platform_fees' => $fees,
            'boosts' => $boosts,
            'subscriptions' => $subscriptions,
            'refunds' => $refunds,
            'payouts' => $payouts,
            // Everything the platform earns, from every source.
            'platform_revenue' => round($fees + $boosts + $subscriptions, 2),
            // Collected on behalf of organizers and not yet paid out. Can go
            // negative if payouts ran ahead of settlement, which is worth seeing.
            'owed_to_organizers' => round($tickets - $refunds - $fees - $payouts, 2),
        ];
    }

    /** Platform revenue per day for the last 30 days — the money we actually keep. */
    private function trend(array $f): array
    {
        $since = Carbon::today()->subDays(29);

        $byDay = DB::query()->fromSub($this->allUnion(), 't')
            ->tap(fn ($q) => $this->applyScope($q, $f))
            ->whereIn('type', ['fee', 'boost', 'subscription'])
            ->where('occurred_at', '>=', $since)
            ->selectRaw('date(occurred_at) as d, sum(amount) as revenue')
            ->groupBy('d')
            ->pluck('revenue', 'd');

        return collect(range(0, 29))->map(function ($i) use ($since, $byDay) {
            $day = $since->copy()->addDays($i);

            return ['date' => $day->format('j M'), 'revenue' => round((float) ($byDay[$day->format('Y-m-d')] ?? 0), 2)];
        })->all();
    }

    /** Where platform revenue came from, plus the two flows either side of it. */
    private function breakdown(array $f): array
    {
        $k = $this->kpis($f);

        return [
            ['label' => 'Platform fees', 'value' => $k['platform_fees'], 'direction' => 'in'],
            ['label' => 'Boosts', 'value' => $k['boosts'], 'direction' => 'in'],
            ['label' => 'Subscriptions', 'value' => $k['subscriptions'], 'direction' => 'in'],
            ['label' => 'Refunds', 'value' => $k['refunds'], 'direction' => 'out'],
            ['label' => 'Payouts', 'value' => $k['payouts'], 'direction' => 'out'],
        ];
    }

    /** The filtered transaction ledger (a UNION over every money source). */
    private function ledger(array $f)
    {
        return DB::query()->fromSub($this->allUnion(), 't')
            ->tap(fn ($q) => $this->applyScope($q, $f))
            ->when($f['type'] !== 'all', fn ($q) => $q->where('type', $f['type']))
            ->when($f['direction'] !== 'all', fn ($q) => $q->where('direction', $f['direction']))
            ->when($f['method'] !== 'all', fn ($q) => $q->where('payment_method', $f['method']))
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$f['q']}%")
                ->orWhere('party', 'like', "%{$f['q']}%")))
            ->orderByDesc('occurred_at');
    }

    /**
     * The filters that both the tiles and the ledger share: who and when.
     *
     * Kept in one place so a tile can never disagree with the rows under it.
     */
    private function applyScope($query, array $f)
    {
        return $query
            // Cast: these arrive from the query string as strings, and SQLite
            // will not match TEXT '1' against an INTEGER column — the filter
            // would silently return nothing.
            ->when($f['event'] !== 'all', fn ($q) => $q->where('event_id', (int) $f['event']))
            ->when($f['organizer'] !== 'all', fn ($q) => $q->where('organizer_id', (int) $f['organizer']))
            ->when($f['from'] !== '', fn ($q) => $q->whereDate('occurred_at', '>=', $f['from']))
            ->when($f['to'] !== '', fn ($q) => $q->whereDate('occurred_at', '<=', $f['to']));
    }

    /** Every money movement. */
    private function allUnion()
    {
        return $this->tickets()
            ->unionAll($this->fees())
            ->unionAll($this->refunds())
            ->unionAll($this->promotions())
            ->unionAll($this->subscriptions())
            ->unionAll($this->payouts());
    }

    /**
     * The gross a buyer paid.
     *
     * Includes refunded orders: the sale happened, and the money coming back out
     * is its own row. Netting it away here would hide both halves.
     */
    private function tickets()
    {
        return DB::table('orders')
            ->leftJoin('events', 'orders.event_id', '=', 'events.id')
            ->whereIn('orders.status', ['paid', 'refunded'])
            ->selectRaw($this->columns(
                type: "'ticket'",
                reference: 'orders.reference',
                party: "coalesce(nullif(orders.buyer_name, ''), 'Guest')",
                amount: 'orders.total',
                direction: "'in'",
                status: 'orders.status',
                occurredAt: 'coalesce(orders.paid_at, orders.created_at)',
                eventId: 'orders.event_id',
                organizerId: 'events.user_id',
                method: 'orders.payment_method',
                brand: 'orders.payment_brand',
            ));
    }

    /** Our commission on a settled order — the line that was missing entirely. */
    private function fees()
    {
        return DB::table('orders')
            ->leftJoin('events', 'orders.event_id', '=', 'events.id')
            ->whereIn('orders.status', ['paid', 'refunded'])
            ->where('orders.fees', '>', 0)
            ->selectRaw($this->columns(
                type: "'fee'",
                reference: 'orders.reference',
                // The organizer pays this, so they are the counterparty — not
                // the buyer, who never saw it.
                party: "coalesce(events.title, 'Event')",
                amount: 'orders.fees',
                direction: "'in'",
                status: "'paid'",
                occurredAt: 'coalesce(orders.paid_at, orders.created_at)',
                eventId: 'orders.event_id',
                organizerId: 'events.user_id',
                method: 'orders.payment_method',
                brand: 'orders.payment_brand',
            ));
    }

    /**
     * Money given back, at the amount ACTUALLY refunded.
     *
     * Keyed on refunded_amount rather than status, because a partial refund
     * leaves the order 'paid' — those were invisible before, and a full refund
     * was being counted at the order total even when it was not.
     */
    private function refunds()
    {
        return DB::table('orders')
            ->leftJoin('events', 'orders.event_id', '=', 'events.id')
            ->where('orders.refunded_amount', '>', 0)
            ->selectRaw($this->columns(
                type: "'refund'",
                reference: 'orders.reference',
                party: "coalesce(nullif(orders.buyer_name, ''), 'Guest')",
                amount: 'orders.refunded_amount',
                direction: "'out'",
                status: "'refunded'",
                occurredAt: 'coalesce(orders.refunded_at, orders.paid_at, orders.created_at)',
                eventId: 'orders.event_id',
                organizerId: 'events.user_id',
                method: 'orders.payment_method',
                brand: 'orders.payment_brand',
            ));
    }

    private function promotions()
    {
        return DB::table('promotions')
            ->leftJoin('events', 'promotions.event_id', '=', 'events.id')
            ->where('promotions.status', 'paid')
            ->selectRaw($this->columns(
                type: "'boost'",
                reference: 'promotions.reference',
                party: "coalesce(events.title, 'Event boost')",
                amount: 'promotions.amount',
                direction: "'in'",
                status: 'promotions.status',
                occurredAt: 'coalesce(promotions.paid_at, promotions.created_at)',
                eventId: 'promotions.event_id',
                organizerId: 'events.user_id',
                method: 'promotions.payment_method',
                brand: 'promotions.payment_brand',
            ));
    }

    private function subscriptions()
    {
        return DB::table('subscriptions')
            ->leftJoin('users', 'subscriptions.user_id', '=', 'users.id')
            ->where('subscriptions.status', 'paid')
            ->selectRaw($this->columns(
                type: "'subscription'",
                reference: 'subscriptions.reference',
                party: "coalesce(users.name, 'Member')",
                amount: 'subscriptions.amount',
                direction: "'in'",
                status: 'subscriptions.status',
                occurredAt: 'coalesce(subscriptions.paid_at, subscriptions.created_at)',
                eventId: 'null',
                organizerId: 'subscriptions.user_id',
                method: 'subscriptions.payment_method',
                brand: 'subscriptions.payment_brand',
            ));
    }

    /**
     * Settlement to an organizer. Always a bank transfer, and the destination
     * bank is on their profile (CHIP Send needs it to make the transfer at all).
     */
    private function payouts()
    {
        return DB::table('payouts')
            ->leftJoin('users', 'payouts.user_id', '=', 'users.id')
            ->where('payouts.status', 'paid')
            ->selectRaw($this->columns(
                type: "'payout'",
                reference: 'payouts.reference',
                party: "coalesce(users.name, 'Organizer')",
                amount: 'payouts.amount',
                direction: "'out'",
                status: 'payouts.status',
                occurredAt: 'coalesce(payouts.paid_at, payouts.created_at)',
                eventId: 'null',
                organizerId: 'payouts.user_id',
                method: "'bank_transfer'",
                brand: 'users.payout_bank_code',
            ));
    }

    /**
     * One select list for every branch.
     *
     * A UNION matches columns by POSITION, so a branch that lists them in a
     * different order silently files amounts under the wrong heading rather
     * than failing. Building the list in one place makes that impossible.
     *
     * Every argument is a SQL fragment written here in this file — no request
     * data reaches it.
     */
    private function columns(
        string $type,
        string $reference,
        string $party,
        string $amount,
        string $direction,
        string $status,
        string $occurredAt,
        string $eventId,
        string $organizerId,
        string $method,
        string $brand,
    ): string {
        return implode(', ', [
            "{$type} as type",
            "{$reference} as reference",
            "{$party} as party",
            "{$amount} as amount",
            "{$direction} as direction",
            "{$status} as status",
            "{$occurredAt} as occurred_at",
            "{$eventId} as event_id",
            "{$organizerId} as organizer_id",
            "{$method} as payment_method",
            "{$brand} as payment_brand",
        ]);
    }

    private function row($t, bool $showMethod): array
    {
        $receipt = match ($t->type) {
            'ticket', 'fee', 'refund' => "/my/orders/{$t->reference}/receipt",
            'payout' => "/my/payouts/{$t->reference}/receipt",
            default => null,
        };

        return [
            'type' => $t->type,
            'reference' => $t->reference,
            'party' => $t->party,
            'amount' => (float) $t->amount,
            'direction' => $t->direction,
            'status' => $t->status,
            'date' => optional(Carbon::parse($t->occurred_at))->format('j M Y'),
            'receipt' => $receipt,
            'payment' => $showMethod ? PaymentMethod::describe($t->payment_method, $t->payment_brand) : null,
        ];
    }
}
