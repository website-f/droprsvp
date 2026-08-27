<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Host overview — aggregates across the signed-in host's own events. */
    public function index(Request $request)
    {
        $user = $request->user();
        $eventIds = $user->events()->pluck('id');

        $revenue = (float) Order::whereIn('event_id', $eventIds)->where('status', 'paid')->sum('total');
        $ticketsSold = Ticket::whereIn('event_id', $eventIds)->whereIn('status', ['valid', 'checked_in'])->count();
        $checkedIn = Ticket::whereIn('event_id', $eventIds)->where('status', 'checked_in')->count();

        return inertia('dashboard', [
            'stats' => [
                'events' => $eventIds->count(),
                'published' => $user->events()->where('status', 'published')->count(),
                'tickets_sold' => $ticketsSold,
                'checked_in' => $checkedIn,
                'revenue' => $revenue,
            ],
            'sales_by_day' => $this->salesByDay($eventIds),
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
                    'when' => optional($e->starts_at)->setTimezone($e->timezone)->format('D, j M · g:i A'),
                    'sold' => $e->sold_count,
                ]),
            'recent_orders' => Order::whereIn('event_id', $eventIds)
                ->where('status', 'paid')
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

    /** Revenue for each of the last 14 days (computed in PHP for DB portability). */
    private function salesByDay($eventIds): array
    {
        $since = now()->subDays(13)->startOfDay();
        $paid = Order::whereIn('event_id', $eventIds)
            ->where('status', 'paid')
            ->where('paid_at', '>=', $since)
            ->get(['paid_at', 'total']);

        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $days[$d->format('Y-m-d')] = ['label' => $d->format('j M'), 'total' => 0.0];
        }
        foreach ($paid as $order) {
            $key = $order->paid_at->format('Y-m-d');
            if (isset($days[$key])) {
                $days[$key]['total'] += (float) $order->total;
            }
        }

        return array_values($days);
    }
}
