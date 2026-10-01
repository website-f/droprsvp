<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventDailyStat;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\GoogleAnalytics;
use App\Support\Analytics;
use App\Support\AnalyticsWindow;
use App\Support\AnswerInsights;
use App\Support\CheckoutFunnel;
use App\Support\PlatformInsights;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $impressionsInWindow = (int) EventDailyStat::whereBetween('stat_date', [$w['from_date'], $w['to_date']])->sum('impressions');

        return inertia('admin/analytics', [
            // Where buyers drop out, and how many could be followed up.
            'funnel' => CheckoutFunnel::summary($w, null, $impressionsInWindow),
            'people' => PlatformInsights::users($w),
            'topCustomers' => PlatformInsights::topCustomers($w, $audience),
            'topOrganizers' => PlatformInsights::topOrganizers($w),
            // Sales in the books vs sales GA has been sent, by which path.
            'gaTally' => GoogleAnalytics::tally($w),
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

    /**
     * Abandoned checkouts: everyone who picked tickets — or went further and
     * filled in their details — but never paid, one row per person per event,
     * for follow-up campaigns.
     */
    public function abandoned(Request $request)
    {
        $w = AnalyticsWindow::fromRequest($request);
        [$event, $stage, $q, $showRecovered] = $this->abandonedFilters($request);

        $impressions = (int) EventDailyStat::query()
            ->when($event, fn ($s) => $s->where('event_id', $event->id))
            ->whereBetween('stat_date', [$w['from_date'], $w['to_date']])->sum('impressions');
        $all = CheckoutFunnel::rows($w, $event?->id);
        $rows = $this->filterAbandoned($all, $stage, $q, $showRecovered);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 25;
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return inertia('admin/analytics/abandoned', [
            'summary' => CheckoutFunnel::summary($w, $event?->id, $impressions),
            'trend' => CheckoutFunnel::trend($w, $event?->id),
            'byEvent' => $event ? [] : CheckoutFunnel::byEvent($all),
            'rows' => $paginator,
            'event' => $event ? ['slug' => $event->slug, 'title' => $event->title] : null,
            'filters' => [
                'period' => $w['period'], 'from' => $w['from_date'], 'to' => $w['to_date'], 'periodLabel' => $w['label'],
                'event' => $event?->slug ?? '', 'stage' => $stage, 'q' => $q, 'recovered' => $showRecovered ? '1' : '',
            ],
            'stageOptions' => collect(CheckoutFunnel::STAGE_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'exportUrl' => route('admin.analytics.abandoned.export', $request->query()),
        ]);
    }

    /** The abandoned-checkout contact list as CSV, ready for an EDM tool. */
    public function abandonedExport(Request $request): StreamedResponse
    {
        $w = AnalyticsWindow::fromRequest($request);
        [$event, $stage, $q, $showRecovered] = $this->abandonedFilters($request);
        $rows = $this->filterAbandoned(CheckoutFunnel::rows($w, $event?->id), $stage, $q, $showRecovered);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Phone', 'Event', 'Event date', 'Stage', 'Tickets', 'Items', 'Basket value', 'Currency', 'Attempts', 'Last attempt', 'Has account', 'Relationship', 'Marketing opt-in', 'City', 'Gender', 'Age', 'Heard via', 'Recovered', 'Order ref']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['name'], $r['email'], $r['phone'], $r['event'], $r['event_date'],
                    CheckoutFunnel::STAGE_LABELS[$r['stage']] ?? $r['stage'],
                    $r['tickets'], $r['items'], number_format($r['value'], 2, '.', ''), $r['currency'], $r['attempts'],
                    $r['last_at_label'], $r['account'] ? 'yes' : 'no',
                    // "Ticked consent at checkout" read as permission to market,
                    // which it never was: that switch is the RSVP terms, and
                    // required to buy. Marketing opt-in is its own column.
                    match ($r['consent']) {
                        'checkout' => 'Agreed to RSVP terms at checkout',
                        'account' => 'Registered member',
                        default => '',
                    },
                    ($r['marketing'] ?? false) ? 'yes' : 'no',
                    $r['city'], $r['gender'], $r['age_band'], $r['source'], $r['recovered'] ? 'yes' : 'no', $r['reference'],
                ]);
            }
            fclose($out);
        }, 'abandoned-checkouts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: ?Event, 1: string, 2: string, 3: bool} */
    private function abandonedFilters(Request $request): array
    {
        $slug = (string) $request->query('event', '');
        $event = $slug !== '' ? Event::where('slug', $slug)->first(['id', 'slug', 'title']) : null;
        $stage = array_key_exists((string) $request->query('stage'), CheckoutFunnel::STAGE_LABELS) ? (string) $request->query('stage') : '';

        return [$event, $stage, trim((string) $request->query('q', '')), $request->boolean('recovered')];
    }

    private function filterAbandoned(Collection $rows, string $stage, string $q, bool $showRecovered): Collection
    {
        $needle = mb_strtolower($q);

        return $rows
            ->when(! $showRecovered, fn ($c) => $c->where('recovered', false))
            ->when($stage !== '', fn ($c) => $c->where('stage', $stage))
            ->when($needle !== '', fn ($c) => $c->filter(fn ($r) => str_contains(
                mb_strtolower(implode(' ', [$r['name'], $r['email'], $r['phone'], $r['event'], $r['reference']])),
                $needle,
            )))
            ->values();
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
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->eventsQuery($request, AnalyticsWindow::fromRequest($request))->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Event', 'Status', 'Date', 'Impressions', 'Clicks', 'CTR %', 'Tickets sold', 'Revenue (RM)', 'Abandoned checkouts']);
            foreach ($rows as $e) {
                $r = $this->eventRow($e);
                fputcsv($out, [$r['title'], $r['status'], $r['when'] ?? '', $r['impressions'], $r['clicks'], $r['ctr'], $r['sold'], number_format($r['revenue'], 2, '.', ''), $r['abandoned']]);
            }
            fclose($out);
        }, 'events-analytics-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Shared query for the table + export: the row aggregates constrained to the
     * selected window, plus search / status / category filters and sort.
     */
    private function eventsQuery(Request $request, array $w): Builder
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
            'abandoned' => 'abandoned',
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
            ->withCount(['orders as abandoned' => fn ($o) => $o->whereNull('paid_at')
                ->whereIn('status', ['pending', 'cancelled', 'failed'])
                ->where(fn ($x) => $x->where('status', '!=', 'pending')->orWhere('created_at', '<', now()->subMinutes(CheckoutFunnel::HOLD_MINUTES)))
                ->whereBetween('created_at', [$from, $to])])
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
            'abandoned' => (int) ($e->abandoned ?? 0),
        ];
    }

    private function sortKey(Request $request): string
    {
        return in_array($request->query('sort'), ['revenue', 'sold', 'impressions', 'abandoned', 'title', 'created_at'], true)
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
            'funnel' => CheckoutFunnel::summary($w, $event->id, (int) $event->dailyStats()->whereBetween('stat_date', [$w['from_date'], $w['to_date']])->sum('impressions')),
            // Same booking-question breakdown the organizer sees.
            'answers' => AnswerInsights::forEvent(
                $event,
                fn ($orders) => Analytics::applyAudience($orders, $city ?: null, $source ?: null),
            ),
            'city' => $city,
            'source' => $source,
            'cityOptions' => $cities,
        ];
    }
}
