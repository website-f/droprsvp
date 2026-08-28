<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDailyStat;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Analytics;

class AnalyticsController extends Controller
{
    /** Platform-wide analytics for the superadmin. */
    public function index()
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
        ]);
    }
}
