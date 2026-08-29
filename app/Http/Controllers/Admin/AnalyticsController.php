<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDailyStat;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Analytics;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /** Platform-wide analytics for the superadmin, with optional per-event drill-down. */
    public function index(Request $request)
    {
        $paid = Order::where('status', 'paid');

        $topEvents = Order::where('status', 'paid')
            ->selectRaw('event_id, SUM(total) as revenue')
            ->groupBy('event_id')->orderByDesc('revenue')->limit(6)
            ->with('event:id,title')->get()
            ->map(fn ($r) => ['name' => $r->event?->title ?? '—', 'value' => round((float) $r->revenue, 2)])
            ->all();

        return inertia('admin/analytics', [
            'kpis' => [
                'events' => Event::count(),
                'published' => Event::where('status', 'published')->count(),
                'users' => User::count(),
                'tickets' => Ticket::whereIn('status', ['valid', 'checked_in'])->count(),
                'revenue' => (float) (clone $paid)->sum('total'),
                'impressions' => (int) EventDailyStat::sum('impressions'),
            ],
            'reach' => Analytics::trendSummed(EventDailyStat::query(), 30),
            'revenue' => Analytics::revenueByDay(Order::query(), 30),
            'topEvents' => $topEvents,
            'demographics' => [
                'gender' => Analytics::breakdown((clone $paid), 'buyer_gender', Analytics::GENDER_LABELS),
                'age' => Analytics::ordered((clone $paid), 'buyer_age_band', Analytics::AGE_ORDER),
                'source' => Analytics::breakdown((clone $paid), 'buyer_source', Analytics::SOURCE_LABELS),
            ],
            // Per-event drill-down.
            'events' => Event::orderByDesc('created_at')->limit(300)->get(['slug', 'title'])
                ->map(fn ($e) => ['slug' => $e->slug, 'title' => $e->title])->all(),
            'selectedSlug' => $request->query('event'),
            'selected' => $this->eventBreakdown($request->query('event')),
        ]);
    }

    /** One event's analytics (same shape as the organizer's per-event page), or null. */
    private function eventBreakdown(?string $slug): ?array
    {
        if (! $slug) {
            return null;
        }

        $event = Event::where('slug', $slug)->first();
        if (! $event) {
            return null;
        }

        $paid = Order::where('event_id', $event->id)->where('status', 'paid');
        $impressions = (int) $event->dailyStats()->sum('impressions');
        $clicks = (int) $event->dailyStats()->sum('clicks');
        $sold = (int) $event->tickets()->whereIn('status', ['valid', 'checked_in'])->count();

        return [
            'event' => ['slug' => $event->slug, 'title' => $event->title, 'status' => $event->status],
            'kpis' => [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 1) : 0.0,
                'sold' => $sold,
                'revenue' => (float) (clone $paid)->sum('total'),
                'conversion' => $clicks > 0 ? round($sold / $clicks * 100, 1) : 0.0,
            ],
            'trend' => Analytics::trend($event->dailyStats(), 30),
            'demographics' => [
                'gender' => Analytics::breakdown((clone $paid), 'buyer_gender', Analytics::GENDER_LABELS),
                'age' => Analytics::ordered((clone $paid), 'buyer_age_band', Analytics::AGE_ORDER),
                'city' => Analytics::top((clone $paid), 'buyer_city', 6),
                'source' => Analytics::breakdown((clone $paid), 'buyer_source', Analytics::SOURCE_LABELS),
            ],
        ];
    }
}
