<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Ticket;
use App\Support\AnalyticsWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Host overview — aggregates across the signed-in host's own events.
     *
     * The numbers follow a period (all time by default; this week, last month,
     * 7/30/90 days, a custom range…) and optionally one event, from the same
     * filter the analytics pages use. Upcoming events and the calendar are
     * about what is ahead, so they are not filtered.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $allIds = $user->events()->pluck('id');

        // One event, when picked from the filter — only ever one of theirs.
        $event = $request->filled('event') ? $user->events()->where('slug', (string) $request->query('event'))->first(['id', 'slug']) : null;
        $eventIds = $event ? collect([$event->id]) : $allIds;

        // "All time" starts at their first sale (or when they joined).
        $first = Order::whereIn('event_id', $allIds)->where('status', 'paid')->min('paid_at');
        $since = CarbonImmutable::parse($first ?? $user->created_at ?? now());
        $w = AnalyticsWindow::fromRequest($request, 'all', $since);

        $paid = Order::whereIn('event_id', $eventIds)->where('status', 'paid')
            ->whereNotNull('paid_at')->whereBetween('paid_at', [$w['from'], $w['to']]);

        $revenue = (float) (clone $paid)->sum(\DB::raw('total - refunded_amount'));
        $orders = (clone $paid)->count();
        // Tickets are issued when their order is paid, so the issue date is the sale date.
        $ticketsSold = Ticket::whereIn('event_id', $eventIds)->whereIn('status', ['valid', 'checked_in'])
            ->whereBetween('created_at', [$w['from'], $w['to']])->count();
        // By check-in time; an older row without one counts from when it was issued.
        $checkedIn = Ticket::whereIn('event_id', $eventIds)->where('status', 'checked_in')
            ->where(fn ($q) => $q->whereBetween('checked_in_at', [$w['from'], $w['to']])
                ->orWhere(fn ($q) => $q->whereNull('checked_in_at')->whereBetween('created_at', [$w['from'], $w['to']])))
            ->count();

        return inertia('dashboard', [
            'stats' => [
                'events' => $eventIds->count(),
                'published' => $user->events()->whereIn('id', $eventIds)->where('status', 'published')->count(),
                'tickets_sold' => $ticketsSold,
                'checked_in' => $checkedIn,
                'revenue' => $revenue,
                'orders' => $orders,
                'average_order' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            ],
            'filters' => [
                'period' => $w['period'], 'from' => $w['from_date'], 'to' => $w['to_date'], 'periodLabel' => $w['label'],
                'event' => $event?->slug ?? '',
            ],
            'eventOptions' => $user->events()->latest('starts_at')->get(['slug', 'title'])
                ->map(fn ($e) => ['value' => $e->slug, 'label' => $e->title])->values(),
            'sales_by_day' => $this->sales((clone $paid), $w),
            'upcoming' => $user->events()
                ->where('status', 'published')
                ->whereNotNull('starts_at')
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at')
                ->withCount(['tickets as sold_count' => fn ($q) => $q->whereIn('status', ['valid', 'checked_in'])])
                ->limit(5)
                ->get()
                ->map(fn ($e) => [
                    'title' => $e->title,
                    'slug' => $e->slug,
                    'when' => $e->starts_at?->setTimezone($e->timezone)->format('D, j M · g:i A'),
                    'sold' => $e->sold_count,
                ]),
            'calendar' => $user->events()
                ->whereNotNull('starts_at')
                ->orderBy('starts_at')
                ->get(['id', 'title', 'slug', 'starts_at', 'timezone', 'venue_name', 'is_online', 'status'])
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'title' => $e->title,
                    'date' => $e->starts_at?->setTimezone($e->timezone)->toDateString(),
                    'time' => $e->starts_at?->setTimezone($e->timezone)->format('g:i A'),
                    'venue' => $e->is_online ? 'Online' : $e->venue_name,
                    'url' => "/host/events/{$e->slug}/edit",
                    'tone' => $e->status === 'published' ? 'default' : 'muted',
                ]),
            'recent_orders' => (clone $paid)
                ->latest('paid_at')
                ->with('event:id,title')
                ->limit(6)
                ->get()
                ->map(fn ($o) => [
                    'reference' => $o->reference,
                    'buyer' => $o->buyer_name,
                    'event' => $o->event?->title,
                    'total' => (float) $o->total,
                    'at' => optional($o->paid_at)->format('j M, g:i A'),
                ]),
        ]);
    }

    /**
     * Revenue in each day of the window — or each month, when the window is
     * longer than a quarter (AnalyticsWindow picks the bucket). Grouped in PHP
     * for DB portability.
     */
    private function sales($paid, array $w): array
    {
        $byMonth = $w['bucket'] === 'month';
        $buckets = [];

        for ($d = CarbonImmutable::parse($w['from_date']); $d->lte($w['to']); $d = $byMonth ? $d->addMonthNoOverflow()->startOfMonth() : $d->addDay()) {
            $buckets[$d->format($byMonth ? 'Y-m' : 'Y-m-d')] = ['label' => $d->format($byMonth ? 'M Y' : 'j M'), 'total' => 0.0];
        }

        foreach ($paid->get(['paid_at', 'total', 'refunded_amount']) as $order) {
            $key = $order->paid_at->format($byMonth ? 'Y-m' : 'Y-m-d');

            if (isset($buckets[$key])) {
                $buckets[$key]['total'] += (float) $order->total - (float) $order->refunded_amount;
            }
        }

        return array_values(array_map(fn ($b) => ['label' => $b['label'], 'total' => round($b['total'], 2)], $buckets));
    }
}
