<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Promotion;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The money view: every transaction that moves through the platform — ticket
 * sales, boosts, premium subscriptions (money in) and organizer payouts (money
 * out) — with KPIs, a revenue trend, and a filterable, paginated ledger.
 */
class FinanceController extends Controller
{
    public function index(Request $request)
    {
        $f = $this->filters($request);

        return inertia('admin/finance', [
            'kpis' => $this->kpis(),
            'trend' => $this->trend(),
            'breakdown' => $this->breakdown(),
            'transactions' => $this->ledger($f)->paginate(20)->withQueryString()
                ->through(fn ($t) => $this->row($t)),
            'filters' => $f,
            'currency' => config('services.chip.currency', 'MYR'),
            'exportUrl' => route('admin.finance.export', array_filter($f, fn ($v) => $v !== '' && $v !== 'all')),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $rows = $this->ledger($f)->orderByDesc('occurred_at')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Type', 'Reference', 'Party', 'Direction', 'Amount', 'Currency', 'Status']);
            foreach ($rows as $t) {
                $r = $this->row($t);
                fputcsv($out, [$r['date'], $r['type'], $r['reference'], $r['party'], $r['direction'], $r['amount'], config('services.chip.currency', 'MYR'), $r['status']]);
            }
            fclose($out);
        }, 'droprsvp-finance-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{q:string,type:string,from:string,to:string} */
    private function filters(Request $request): array
    {
        $types = ['ticket', 'boost', 'subscription', 'payout', 'refund'];

        return [
            'q' => trim((string) $request->query('q', '')),
            'type' => in_array($request->query('type'), $types, true) ? $request->query('type') : 'all',
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];
    }

    private function kpis(): array
    {
        return [
            'ticket_sales' => (float) Order::whereIn('status', ['paid', 'refunded'])->sum('total'),
            'boosts' => (float) Promotion::where('status', 'paid')->sum('amount'),
            'subscriptions' => (float) Subscription::where('status', 'paid')->sum('amount'),
            'refunds' => (float) Order::sum('refunded_amount'),
            'payouts' => (float) Payout::where('status', 'paid')->sum('amount'),
        ];
    }

    /** Money-in per day for the last 30 days (tickets + boosts + subscriptions). */
    private function trend(): array
    {
        $since = Carbon::today()->subDays(29);
        $byDay = DB::query()->fromSub($this->inflowUnion(), 't')
            ->where('occurred_at', '>=', $since)
            ->selectRaw('date(occurred_at) as d, sum(amount) as revenue')
            ->groupBy('d')->pluck('revenue', 'd');

        return collect(range(0, 29))->map(function ($i) use ($since, $byDay) {
            $day = $since->copy()->addDays($i);

            return ['date' => $day->format('j M'), 'revenue' => round((float) ($byDay[$day->format('Y-m-d')] ?? 0), 2)];
        })->all();
    }

    private function breakdown(): array
    {
        $k = $this->kpis();

        return [
            ['label' => 'Ticket sales', 'value' => round($k['ticket_sales'], 2)],
            ['label' => 'Boosts', 'value' => round($k['boosts'], 2)],
            ['label' => 'Subscriptions', 'value' => round($k['subscriptions'], 2)],
        ];
    }

    /** The filtered transaction ledger (a UNION over every money source). */
    private function ledger(array $f)
    {
        $query = DB::query()->fromSub($this->allUnion(), 't')
            ->when($f['type'] !== 'all', fn ($q) => $q->where('type', $f['type']))
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$f['q']}%")->orWhere('party', 'like', "%{$f['q']}%")))
            ->when($f['from'] !== '', fn ($q) => $q->whereDate('occurred_at', '>=', $f['from']))
            ->when($f['to'] !== '', fn ($q) => $q->whereDate('occurred_at', '<=', $f['to']))
            ->orderByDesc('occurred_at');

        return $query;
    }

    /** In-flows only (for revenue trend + breakdown). */
    private function inflowUnion()
    {
        return $this->orders(['paid'])->unionAll($this->promotions())->unionAll($this->subscriptions());
    }

    /** Every money movement (for the ledger). */
    private function allUnion()
    {
        return $this->orders(['paid', 'refunded'])
            ->unionAll($this->promotions())
            ->unionAll($this->subscriptions())
            ->unionAll($this->payouts());
    }

    private function orders(array $statuses)
    {
        return DB::table('orders')
            ->leftJoin('events', 'orders.event_id', '=', 'events.id')
            ->whereIn('orders.status', $statuses)
            ->selectRaw("case orders.status when 'refunded' then 'refund' else 'ticket' end as type, orders.reference as reference, coalesce(nullif(orders.buyer_name, ''), 'Guest') as party, orders.total as amount, case orders.status when 'refunded' then 'out' else 'in' end as direction, orders.status as status, coalesce(orders.paid_at, orders.created_at) as occurred_at");
    }

    private function promotions()
    {
        return DB::table('promotions')
            ->leftJoin('events', 'promotions.event_id', '=', 'events.id')
            ->where('promotions.status', 'paid')
            ->selectRaw("'boost' as type, promotions.reference as reference, coalesce(events.title, 'Event boost') as party, promotions.amount as amount, 'in' as direction, promotions.status as status, coalesce(promotions.paid_at, promotions.created_at) as occurred_at");
    }

    private function subscriptions()
    {
        return DB::table('subscriptions')
            ->leftJoin('users', 'subscriptions.user_id', '=', 'users.id')
            ->where('subscriptions.status', 'paid')
            ->selectRaw("'subscription' as type, subscriptions.reference as reference, coalesce(users.name, 'Member') as party, subscriptions.amount as amount, 'in' as direction, subscriptions.status as status, coalesce(subscriptions.paid_at, subscriptions.created_at) as occurred_at");
    }

    private function payouts()
    {
        return DB::table('payouts')
            ->leftJoin('users', 'payouts.user_id', '=', 'users.id')
            ->where('payouts.status', 'paid')
            ->selectRaw("'payout' as type, payouts.reference as reference, coalesce(users.name, 'Organizer') as party, payouts.amount as amount, 'out' as direction, payouts.status as status, coalesce(payouts.paid_at, payouts.created_at) as occurred_at");
    }

    private function row($t): array
    {
        $receipt = match ($t->type) {
            'ticket', 'refund' => "/my/orders/{$t->reference}/receipt",
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
        ];
    }
}
