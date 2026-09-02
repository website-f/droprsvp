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
            // Advanced, scalable events table (search + sort + paginate) — replaces
            // the old "pick from every event" dropdown.
            'events' => $this->eventsQuery($request)->paginate(15)->withQueryString()
                ->through(fn (Event $e) => $this->eventRow($e)),
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'sort' => $this->sortKey($request),
                'dir' => $this->sortDir($request),
            ],
            'exportUrl' => route('admin.analytics.export', $request->query()),
        ]);
    }

    /** One event's analytics on its own page (opened from the events table). */
    public function show(Event $event)
    {
        $data = $this->eventBreakdown($event->slug);
        abort_unless($data, 404);

        return inertia('admin/analytics/event', ['data' => $data]);
    }

    /** Stream the (filtered) events table as CSV. */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = $this->eventsQuery($request)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Event', 'Status', 'Date', 'Impressions', 'Clicks', 'CTR %', 'Tickets sold', 'Revenue (RM)']);
            foreach ($rows as $e) {
                $r = $this->eventRow($e);
                fputcsv($out, [$r['title'], $r['status'], $r['when'] ?? '', $r['impressions'], $r['clicks'], $r['ctr'], $r['sold'], number_format($r['revenue'], 2, '.', '')]);
            }
            fclose($out);
        }, 'events-analytics-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Shared query for the table + export: search + sort + the row aggregates. */
    private function eventsQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $q = trim((string) $request->query('q', ''));
        $sort = $this->sortKey($request);
        $dir = $this->sortDir($request);

        $column = match ($sort) {
            'revenue' => 'revenue',
            'sold' => 'sold',
            'impressions' => 'impressions',
            'title' => 'title',
            default => 'created_at',
        };

        return Event::query()
            ->when($q !== '', fn ($b) => $b->where('title', 'like', "%{$q}%"))
            ->withCount(['tickets as sold' => fn ($t) => $t->whereIn('status', ['valid', 'checked_in'])])
            ->withSum('dailyStats as impressions', 'impressions')
            ->withSum('dailyStats as clicks', 'clicks')
            ->withSum(['orders as revenue' => fn ($o) => $o->where('status', 'paid')], 'total')
            ->orderBy($column, $dir);
    }

    private function eventRow(Event $e): array
    {
        $impressions = (int) ($e->impressions ?? 0);
        $clicks = (int) ($e->clicks ?? 0);

        return [
            'slug' => $e->slug,
            'title' => $e->title,
            'status' => $e->status,
            'when' => $e->starts_at?->setTimezone($e->timezone)->format('j M Y'),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 1) : 0.0,
            'sold' => (int) ($e->sold ?? 0),
            'revenue' => round((float) ($e->revenue ?? 0), 2),
        ];
    }

    private function sortKey(Request $request): string
    {
        return in_array($request->query('sort'), ['revenue', 'sold', 'impressions', 'title', 'created_at'], true)
            ? $request->query('sort') : 'created_at';
    }

    private function sortDir(Request $request): string
    {
        return $request->query('dir') === 'asc' ? 'asc' : 'desc';
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
