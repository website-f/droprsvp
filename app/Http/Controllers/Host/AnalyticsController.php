<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDailyStat;
use App\Models\Order;
use App\Models\Ticket;
use App\Support\Analytics;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /** Analytics across ALL of the organizer's events, with links to drill into each. */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $eventIds = Event::where('user_id', $userId)->pluck('id');
        $paid = Order::whereIn('event_id', $eventIds)->where('status', 'paid');

        return inertia('host/analytics', [
            'kpis' => [
                'events' => $eventIds->count(),
                'impressions' => (int) EventDailyStat::whereIn('event_id', $eventIds)->sum('impressions'),
                'clicks' => (int) EventDailyStat::whereIn('event_id', $eventIds)->sum('clicks'),
                'tickets' => (int) Ticket::whereIn('event_id', $eventIds)->whereIn('status', ['valid', 'checked_in'])->count(),
                'revenue' => (float) (clone $paid)->sum('total'),
            ],
            'reach' => Analytics::trendSummed(EventDailyStat::whereIn('event_id', $eventIds), 30),
            'revenue' => Analytics::revenueByDay(Order::whereIn('event_id', $eventIds), 30),
            'events' => Event::where('user_id', $userId)->latest()->get()->map(fn (Event $e) => [
                'slug' => $e->slug,
                'title' => $e->title,
                'status' => $e->status,
                'impressions' => (int) $e->dailyStats()->sum('impressions'),
                'sold' => (int) $e->tickets()->whereIn('status', ['valid', 'checked_in'])->count(),
                'revenue' => (float) Order::where('event_id', $e->id)->where('status', 'paid')->sum('total'),
            ])->all(),
        ]);
    }

    /** Per-event analytics: reach (impressions/clicks), sales and audience demographics. */
    public function show(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $trend = Analytics::trend($event->dailyStats(), 30);

        $impressions = (int) $event->dailyStats()->sum('impressions');
        $clicks = (int) $event->dailyStats()->sum('clicks');
        $paid = Order::where('event_id', $event->id)->where('status', 'paid');
        $sold = (int) $event->tickets()->whereIn('status', ['valid', 'checked_in'])->count();
        $revenue = (float) (clone $paid)->sum('total');

        return inertia('host/events/analytics', [
            'event' => ['slug' => $event->slug, 'title' => $event->title, 'status' => $event->status],
            'kpis' => [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 1) : 0.0,
                'sold' => $sold,
                'revenue' => $revenue,
                'conversion' => $clicks > 0 ? round($sold / $clicks * 100, 1) : 0.0,
            ],
            'trend' => $trend,
            'demographics' => [
                'gender' => Analytics::breakdown((clone $paid), 'buyer_gender', Analytics::GENDER_LABELS),
                'age' => Analytics::ordered((clone $paid), 'buyer_age_band', Analytics::AGE_ORDER),
                'city' => Analytics::top((clone $paid), 'buyer_city', 6),
                'source' => Analytics::breakdown((clone $paid), 'buyer_source', Analytics::SOURCE_LABELS),
            ],
        ]);
    }
}
