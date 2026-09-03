<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventDailyStat;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Analytics;
use App\Support\AnalyticsWindow;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /** Event statuses offered in the table's status filter. */
    private const STATUSES = ['draft', 'pending', 'published', 'cancelled'];

    /** Platform-wide analytics for the superadmin, with optional per-event drill-down. */
    public function index(Request $request)
    {
        $w = AnalyticsWindow::fromRequest($request);
        $paid = Order::where('status', 'paid');
        // Sales-derived views (charts, demographics, top events) respect the window;
        // the headline KPI cards stay as platform-wide totals.
        $paidInWindow = (clone $paid)->whereNotNull('paid_at')->whereBetween('paid_at', [$w['from'], $w['to']]);

        // Audience filters (city + traffic source) narrow the buyer-derived views.
        $cities = Analytics::cityOptions((clone $paid));
        $city = in_array($request->query('city'), $cities, true) ? $request->query('city') : '';
        $source = array_key_exists($request->query('source'), Analytics::SOURCE_LABELS) ? $request->query('source') : '';
        $audience = fn ($q) => Analytics::applyAudience($q, $city ?: null, $source ?: null);
        $paidInWindow = $audience($paidInWindow);

        $topEvents = (clone $paidInWindow)
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
                'revenue' => (float) (clone $paid)->sum(\DB::raw('total - refunded_amount')),
                'impressions' => (int) EventDailyStat::sum('impressions'),
            ],
            'reach' => Analytics::reach(EventDailyStat::query(), $w),
            'revenue' => Analytics::revenue($audience(Order::query()), $w),
            'topEvents' => $topEvents,
            'demographics' => [
                'gender' => Analytics::breakdown((clone $paidInWindow), 'buyer_gender', Analytics::GENDER_LABELS),
                'age' => Analytics::ordered((clone $paidInWindow), 'buyer_age_band', Analytics::AGE_ORDER),
                'source' => Analytics::breakdown((clone $paidInWindow), 'buyer_source', Analytics::SOURCE_LABELS),
            ],
            // Advanced, scalable events table: window + search + status + category
            // + sort + paginate — replaces the old "pick from every event" dropdown.
            'events' => $this->eventsQuery($request, $w)->paginate(15)->withQueryString()
                ->through(fn (Event $e) => $this->eventRow($e)),
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'sort' => $this->sortKey($request),
                'dir' => $this->sortDir($request),
                'status' => $this->statusFilter($request),
                'category' => $this->categoryFilter($request),
                'city' => $city,
                'source' => $source,
                'period' => $w['period'],
                'from' => $w['from_date'],
                'to' => $w['to_date'],
                'periodLabel' => $w['label'],
            ],
            'statusOptions' => self::STATUSES,
            'categoryOptions' => EventCategory::orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'cityOptions' => $cities,
            'sourceOptions' => Analytics::sourceOptions(),
            'exportUrl' => route('admin.analytics.export', $request->query()),
        ]);
    }

    /** One event's analytics on its own page (opened from the events table). */
    public function show(Request $request, Event $event)
    {
        $w = AnalyticsWindow::fromRequest($request);
        $data = $this->eventBreakdown($event->slug, $w, $request);
        abort_unless($data, 404);

        return inertia('admin/analytics/event', [
            'data' => $data,
            'filters' => ['period' => $w['period'], 'from' => $w['from_date'], 'to' => $w['to_date'], 'periodLabel' => $w['label'], 'city' => $data['city'], 'source' => $data['source']],
            'cityOptions' => $data['cityOptions'],
            'sourceOptions' => Analytics::sourceOptions(),
        ]);
    }

    /** Stream the (filtered) events table as CSV. */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = $this->eventsQuery($request, AnalyticsWindow::fromRequest($request))->get();

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

    /**
     * Shared query for the table + export: the row aggregates constrained to the
     * selected window, plus search / status / category filters and sort.
     */
    private function eventsQuery(Request $request, array $w): \Illuminate\Database\Eloquent\Builder
    {
        $q = trim((string) $request->query('q', ''));
        $status = $this->statusFilter($request);
        $category = $this->categoryFilter($request);
        $sort = $this->sortKey($request);
        $dir = $this->sortDir($request);
        [$from, $to] = [$w['from'], $w['to']];

        $column = match ($sort) {
            'revenue' => 'revenue',
            'sold' => 'sold',
            'impressions' => 'impressions',
            'title' => 'title',
            default => 'created_at',
        };

        return Event::query()
            ->when($q !== '', fn ($b) => $b->where('title', 'like', "%{$q}%"))
            ->when($status !== '', fn ($b) => $b->where('status', $status))
            ->when($category !== '', fn ($b) => $b->where('category_id', $category))
            ->withCount(['tickets as sold' => fn ($t) => $t->whereIn('status', ['valid', 'checked_in'])->whereBetween('created_at', [$from, $to])])
            ->withSum(['dailyStats as impressions' => fn ($s) => $s->whereBetween('stat_date', [$w['from_date'], $w['to_date']])], 'impressions')
            ->withSum(['dailyStats as clicks' => fn ($s) => $s->whereBetween('stat_date', [$w['from_date'], $w['to_date']])], 'clicks')
            ->withSum(['orders as revenue' => fn ($o) => $o->where('status', 'paid')->whereBetween('paid_at', [$from, $to])], \DB::raw('total - refunded_amount'))
            ->orderBy($column, $dir);
    }

    /** Sanitised status filter ('' = all). */
    private function statusFilter(Request $request): string
    {
        $s = (string) $request->query('status', '');

        return in_array($s, self::STATUSES, true) ? $s : '';
    }

    /** Sanitised category id filter ('' = all). */
    private function categoryFilter(Request $request): string
    {
        $c = (string) $request->query('category', '');

        return ($c !== '' && EventCategory::whereKey($c)->exists()) ? $c : '';
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
    private function eventBreakdown(?string $slug, array $w, Request $request): ?array
    {
        if (! $slug) {
            return null;
        }

        $event = Event::where('slug', $slug)->first();
        if (! $event) {
            return null;
        }

        $paid = Order::where('event_id', $event->id)->where('status', 'paid');
        $cities = Analytics::cityOptions((clone $paid));
        $city = in_array($request->query('city'), $cities, true) ? $request->query('city') : '';
        $source = array_key_exists($request->query('source'), Analytics::SOURCE_LABELS) ? $request->query('source') : '';
        $paidInWindow = Analytics::applyAudience(
            (clone $paid)->whereNotNull('paid_at')->whereBetween('paid_at', [$w['from'], $w['to']]),
            $city ?: null,
            $source ?: null,
        );
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
                'revenue' => (float) (clone $paid)->sum(\DB::raw('total - refunded_amount')),
                'conversion' => $clicks > 0 ? round($sold / $clicks * 100, 1) : 0.0,
            ],
            'trend' => Analytics::reach($event->dailyStats(), $w),
            'demographics' => [
                'gender' => Analytics::breakdown((clone $paidInWindow), 'buyer_gender', Analytics::GENDER_LABELS),
                'age' => Analytics::ordered((clone $paidInWindow), 'buyer_age_band', Analytics::AGE_ORDER),
                'city' => Analytics::top((clone $paidInWindow), 'buyer_city', 6),
                'source' => Analytics::breakdown((clone $paidInWindow), 'buyer_source', Analytics::SOURCE_LABELS),
            ],
            'city' => $city,
            'source' => $source,
            'cityOptions' => $cities,
        ];
    }
}
