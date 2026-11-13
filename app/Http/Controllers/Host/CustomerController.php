<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\EmailConsent;
use App\Models\Event;
use App\Support\Dates;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everyone who has bought from this organizer, across all their events — one
 * row per person (by email), with what they bought and when. The per-event
 * attendee list answers "who is coming to this"; this answers "who are my
 * customers", and exports them.
 *
 * Built as one grouped query over paid orders, so a list of thousands pages
 * and exports without loading every order into PHP.
 */
class CustomerController extends Controller
{
    private const SORTS = [
        'recent' => 'last_at',
        'spent' => 'spent',
        'orders' => 'orders',
        'name' => 'name',
    ];

    public function index(Request $request)
    {
        $events = $this->events($request);
        [$event, $q, $sort] = $this->filters($request, $events);

        $rows = DB::query()
            ->fromSub($this->grouped($events->pluck('id')->all(), $event?->id, $q), 'c')
            ->when($sort === 'name', fn ($w) => $w->orderBy('name'), fn ($w) => $w->orderByDesc(self::SORTS[$sort]))
            ->orderBy('email')
            ->paginate(25)
            ->withQueryString();

        $titles = $events->pluck('title', 'id');
        $subscribed = $this->subscribed($request, collect($rows->items())->pluck('email')->all());

        $totals = DB::query()->fromSub($this->grouped($events->pluck('id')->all(), $event?->id, $q), 't')
            ->selectRaw('COUNT(*) as people, COALESCE(SUM(orders), 0) as orders, COALESCE(SUM(tickets), 0) as tickets, COALESCE(SUM(spent), 0) as spent')
            ->first();

        return inertia('host/customers', [
            'customers' => [
                'data' => collect($rows->items())->map(fn ($r) => $this->row($r, $titles->all(), $subscribed))->all(),
                'links' => $rows->linkCollection(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
                'total' => $rows->total(),
            ],
            'totals' => [
                'people' => (int) $totals->people,
                'orders' => (int) $totals->orders,
                'tickets' => (int) $totals->tickets,
                'spent' => round((float) $totals->spent, 2),
            ],
            'filters' => ['q' => $q, 'event' => $event?->slug ?? '', 'sort' => $sort],
            'events' => $events->map(fn ($e) => ['value' => $e->slug, 'label' => $e->title])->values(),
        ]);
    }

    /** The same list (with the same filters) as a CSV, streamed row by row. */
    public function export(Request $request): StreamedResponse
    {
        $events = $this->events($request);
        [$event, $q] = $this->filters($request, $events);
        $titles = $events->pluck('title', 'id')->all();
        $ids = $events->pluck('id')->all();
        $organizerIds = $request->user()->manageableOwnerIds();

        $name = 'customers'.($event ? '-'.$event->slug : '').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($ids, $event, $q, $titles, $organizerIds) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 names correctly with a BOM
            fputcsv($out, ['Name', 'Email', 'Phone', 'City', 'Orders', 'Tickets', 'Total spent (RM)', 'Events', 'First purchase', 'Last purchase', 'Gets your emails']);

            // A page at a time, so thousands of customers never sit in memory at once.
            DB::query()->fromSub($this->grouped($ids, $event?->id, $q), 'c')
                ->orderBy('email')
                ->chunk(500, function ($chunk) use ($out, $titles, $organizerIds) {
                    $subscribed = EmailConsent::whereIn('organizer_id', $organizerIds)
                        ->where('status', 'subscribed')
                        ->whereIn('email', $chunk->pluck('email')->all())
                        ->pluck('email')->flip()->all();

                    foreach ($chunk as $r) {
                        $row = $this->row($r, $titles, $subscribed);
                        fputcsv($out, [
                            $row['name'], $row['email'], $row['phone'], $row['city'],
                            $row['orders'], $row['tickets'], number_format($row['spent'], 2, '.', ''),
                            implode('; ', $row['events']), $row['first_at'], $row['last_at'],
                            $row['subscribed'] ? 'Yes' : 'No',
                        ]);
                    }
                });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** One row per buyer email over the organizer's paid orders. */
    private function grouped(array $eventIds, ?int $eventId, string $q): QueryBuilder
    {
        // Tickets per order, joined in (an aggregate over a correlated
        // subquery is not something every database accepts).
        $tickets = DB::table('order_items')->groupBy('order_id')->selectRaw('order_id, SUM(quantity) as qty');

        return DB::table('orders')
            ->leftJoinSub($tickets, 'oi', 'oi.order_id', '=', 'orders.id')
            ->whereIn('event_id', $eventId ? [$eventId] : ($eventIds ?: [0]))
            ->where('status', 'paid')
            ->whereNotNull('buyer_email')
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('buyer_name', 'like', "%{$q}%")
                ->orWhere('buyer_email', 'like', "%{$q}%")
                ->orWhere('buyer_phone', 'like', "%{$q}%")))
            ->groupBy(DB::raw('LOWER(buyer_email)'))
            ->selectRaw('LOWER(buyer_email) as email')
            ->selectRaw('MAX(buyer_name) as name')
            ->selectRaw('MAX(buyer_phone) as phone')
            ->selectRaw('MAX(buyer_city) as city')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(COALESCE(oi.qty, 0)) as tickets')
            ->selectRaw('SUM(total - refunded_amount) as spent')
            ->selectRaw('MIN(paid_at) as first_at')
            ->selectRaw('MAX(paid_at) as last_at')
            ->selectRaw('GROUP_CONCAT(DISTINCT event_id) as event_ids');
    }

    /** @return array<string, mixed> */
    private function row(object $r, array $titles, array $subscribed): array
    {
        $eventIds = array_filter(explode(',', (string) $r->event_ids));

        return [
            'name' => $r->name ?: '—',
            'email' => $r->email,
            'phone' => $r->phone,
            'city' => $r->city,
            'orders' => (int) $r->orders,
            'tickets' => (int) $r->tickets,
            'spent' => round((float) $r->spent, 2),
            'events' => array_values(array_filter(array_map(fn ($id) => $titles[(int) $id] ?? null, $eventIds))),
            'first_at' => Dates::display($r->first_at ? Carbon::parse($r->first_at) : null, 'j M Y'),
            'last_at' => Dates::display($r->last_at ? Carbon::parse($r->last_at) : null, 'j M Y'),
            'subscribed' => isset($subscribed[$r->email]),
        ];
    }

    /** Emails (of the given ones) that agreed to hear from this organizer. */
    private function subscribed(Request $request, array $emails): array
    {
        return EmailConsent::whereIn('organizer_id', $request->user()->manageableOwnerIds())
            ->where('status', 'subscribed')
            ->whereIn('email', $emails)
            ->pluck('email')->flip()->all();
    }

    private function events(Request $request)
    {
        return Event::whereIn('user_id', $request->user()->manageableOwnerIds())
            ->latest('starts_at')
            ->get(['id', 'slug', 'title']);
    }

    /** @return array{0: ?Event, 1: string, 2: string} */
    private function filters(Request $request, $events): array
    {
        $event = $request->filled('event') ? $events->firstWhere('slug', (string) $request->query('event')) : null;
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'recent';

        return [$event, $q, $sort];
    }
}
