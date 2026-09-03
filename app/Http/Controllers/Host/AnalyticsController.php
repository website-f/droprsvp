<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDailyStat;
use App\Models\Order;
use App\Models\Ticket;
use App\Support\Analytics;
use App\Support\AnalyticsWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /** Analytics across ALL of the organizer's events, with links to drill into each. */
    public function index(Request $request)
    {
        $w = AnalyticsWindow::fromRequest($request);
        $eventIds = Event::whereIn('user_id', $request->user()->manageableOwnerIds())->pluck('id');
        $paid = Order::whereIn('event_id', $eventIds)->where('status', 'paid');

        // Audience filters (city + source) narrow the buyer-derived views.
        $cities = Analytics::cityOptions((clone $paid));
        $city = in_array($request->query('city'), $cities, true) ? $request->query('city') : '';
        $source = array_key_exists($request->query('source'), Analytics::SOURCE_LABELS) ? $request->query('source') : '';

        return inertia('host/analytics', [
            'kpis' => [
                'events' => $eventIds->count(),
                'impressions' => (int) EventDailyStat::whereIn('event_id', $eventIds)->sum('impressions'),
                'clicks' => (int) EventDailyStat::whereIn('event_id', $eventIds)->sum('clicks'),
                'tickets' => (int) Ticket::whereIn('event_id', $eventIds)->whereIn('status', ['valid', 'checked_in'])->count(),
                'revenue' => (float) (clone $paid)->sum(DB::raw('total - refunded_amount')),
            ],
            'reach' => Analytics::reach(EventDailyStat::whereIn('event_id', $eventIds), $w),
            'revenue' => Analytics::revenue(Analytics::applyAudience(Order::whereIn('event_id', $eventIds), $city ?: null, $source ?: null), $w),
            'events' => Event::whereIn('id', $eventIds)->latest()->get()->map(fn (Event $e) => [
                'slug' => $e->slug,
                'title' => $e->title,
                'status' => $e->status,
                'impressions' => (int) $e->dailyStats()->sum('impressions'),
                'sold' => (int) $e->tickets()->whereIn('status', ['valid', 'checked_in'])->count(),
                'revenue' => (float) Order::where('event_id', $e->id)->where('status', 'paid')->sum(DB::raw('total - refunded_amount')),
            ])->all(),
            'filters' => ['period' => $w['period'], 'from' => $w['from_date'], 'to' => $w['to_date'], 'periodLabel' => $w['label'], 'city' => $city, 'source' => $source],
            'cityOptions' => $cities,
            'sourceOptions' => Analytics::sourceOptions(),
        ]);
    }

    /** Per-event analytics: reach (impressions/clicks), sales and audience demographics. */
    public function show(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $w = AnalyticsWindow::fromRequest($request);
        $trend = Analytics::reach($event->dailyStats(), $w);

        $impressions = (int) $event->dailyStats()->sum('impressions');
        $clicks = (int) $event->dailyStats()->sum('clicks');
        $paid = Order::where('event_id', $event->id)->where('status', 'paid');
        $cities = Analytics::cityOptions((clone $paid));
        $city = in_array($request->query('city'), $cities, true) ? $request->query('city') : '';
        $source = array_key_exists($request->query('source'), Analytics::SOURCE_LABELS) ? $request->query('source') : '';
        $paidInWindow = Analytics::applyAudience(
            (clone $paid)->whereNotNull('paid_at')->whereBetween('paid_at', [$w['from'], $w['to']]),
            $city ?: null,
            $source ?: null,
        );
        $sold = (int) $event->tickets()->whereIn('status', ['valid', 'checked_in'])->count();
        $revenue = (float) (clone $paid)->sum(DB::raw('total - refunded_amount'));

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
                'gender' => Analytics::breakdown((clone $paidInWindow), 'buyer_gender', Analytics::GENDER_LABELS),
                'age' => Analytics::ordered((clone $paidInWindow), 'buyer_age_band', Analytics::AGE_ORDER),
                'city' => Analytics::top((clone $paidInWindow), 'buyer_city', 6),
                'source' => Analytics::breakdown((clone $paidInWindow), 'buyer_source', Analytics::SOURCE_LABELS),
            ],
            'filters' => ['period' => $w['period'], 'from' => $w['from_date'], 'to' => $w['to_date'], 'periodLabel' => $w['label'], 'city' => $city, 'source' => $source],
            'cityOptions' => $cities,
            'sourceOptions' => Analytics::sourceOptions(),
        ]);
    }
}
