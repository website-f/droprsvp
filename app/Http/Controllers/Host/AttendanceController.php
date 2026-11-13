<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\CheckInService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who came on which day — for events that check people in every day (a
 * week-long programme, a volunteer drive of 800 people).
 *
 * A grid of people × days, filterable to "present on" / "absent on" a day,
 * and two exports: the grid itself (one row per person, one column per day)
 * and the raw scan log (one row per check-in, with who scanned it). Both
 * stream in chunks, so the size of the event does not matter.
 */
class AttendanceController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $days = $this->days($event);
        $filters = $this->filters($request, $days);
        $live = $event->tickets()->whereIn('status', ['valid', 'checked_in']);

        $present = CheckIn::where('event_id', $event->id)
            ->whereIn('ticket_id', (clone $live)->select('id'))
            ->selectRaw('day, COUNT(*) as n')
            ->groupBy('day')
            ->pluck('n', 'day');

        $tickets = $this->query($event, $filters)
            ->with(['ticketType:id,name', 'order:id,reference,buyer_name,buyer_email,buyer_phone', 'checkIns:id,ticket_id,day,checked_in_at'])
            ->orderBy('attendee_name')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return inertia('host/events/attendance', [
            'event' => [
                'title' => $event->title, 'slug' => $event->slug,
                'daily' => (bool) $event->daily_checkin,
                'today' => app(CheckInService::class)->today($event),
            ],
            'admissions' => (clone $live)->count(),
            'days' => array_map(fn (string $d) => [
                'date' => $d,
                'label' => CarbonImmutable::parse($d)->format('D, j M'),
                'present' => (int) ($present[$d] ?? 0),
            ], $days),
            'rows' => [
                'data' => collect($tickets->items())->map(fn (Ticket $t) => $this->row($t, $event))->all(),
                'links' => $tickets->linkCollection(),
                'from' => $tickets->firstItem(),
                'to' => $tickets->lastItem(),
                'total' => $tickets->total(),
            ],
            'filters' => $filters,
            'ticketTypes' => $event->ticketTypes()->orderBy('sort_order')->get(['id', 'name'])->map(fn ($t) => ['value' => (string) $t->id, 'label' => $t->name])->values(),
        ]);
    }

    /**
     * CSV of the attendance grid — one row per person, one column per day
     * (the time they were checked in, blank when absent), then days attended.
     * With ?format=log, one row per check-in instead, with who scanned it.
     */
    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->authorize('update', $event);

        $days = $this->days($event);
        $filters = $this->filters($request, $days);
        $tz = $event->timezone ?: config('app.display_timezone', 'Asia/Kuala_Lumpur');

        if ($request->query('format') === 'log') {
            return response()->streamDownload(function () use ($event, $tz) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Day', 'Time', 'Name', 'Email', 'Phone', 'Ticket type', 'Order', 'Checked in by', 'How']);

                CheckIn::where('event_id', $event->id)
                    ->with(['ticket:id,order_id,ticket_type_id,attendee_name,attendee_email', 'ticket.order:id,reference,buyer_name,buyer_email,buyer_phone', 'ticket.ticketType:id,name', 'scanner:id,name'])
                    ->orderBy('day')->orderBy('checked_in_at')
                    ->lazyById(500)
                    ->each(function (CheckIn $c) use ($out, $tz) {
                        $t = $c->ticket;
                        fputcsv($out, [
                            substr((string) $c->day, 0, 10),
                            $c->checked_in_at->setTimezone($tz)->format('g:i A'),
                            $t?->attendee_name ?: ($t?->order?->buyer_name ?: 'Guest'),
                            $t?->attendee_email ?: $t?->order?->buyer_email,
                            $t?->order?->buyer_phone,
                            $t?->ticketType?->name,
                            $t?->order?->reference,
                            $c->scanner?->name,
                            $c->via === 'manual' ? 'Manual' : 'QR scan',
                        ]);
                    });

                fclose($out);
            }, 'check-in-log-'.$event->slug.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->streamDownload(function () use ($event, $filters, $days, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(
                ['Name', 'Email', 'Phone', 'Ticket type', 'Order'],
                array_map(fn (string $d) => CarbonImmutable::parse($d)->format('D j M Y'), $days),
                ['Days attended'],
            ));

            $this->query($event, $filters)
                ->with(['ticketType:id,name', 'order:id,reference,buyer_name,buyer_email,buyer_phone', 'checkIns:id,ticket_id,day,checked_in_at'])
                ->lazyById(500)
                ->each(function (Ticket $t) use ($out, $days, $tz) {
                    $byDay = $t->checkIns->keyBy(fn (CheckIn $c) => substr((string) $c->day, 0, 10));
                    fputcsv($out, array_merge(
                        [$t->attendee_name ?: ($t->order?->buyer_name ?: 'Guest'), $t->attendee_email ?: $t->order?->buyer_email, $t->order?->buyer_phone, $t->ticketType?->name, $t->order?->reference],
                        array_map(fn (string $d) => isset($byDay[$d]) ? $byDay[$d]->checked_in_at->setTimezone($tz)->format('g:i A') : '', $days),
                        [$t->checkIns->count()],
                    ));
                });

            fclose($out);
        }, 'attendance-'.$event->slug.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function row(Ticket $t, Event $event): array
    {
        $tz = $event->timezone ?: config('app.display_timezone', 'Asia/Kuala_Lumpur');

        return [
            'id' => $t->id,
            'name' => $t->attendee_name ?: ($t->order?->buyer_name ?: 'Guest'),
            'email' => $t->attendee_email ?: $t->order?->buyer_email,
            'phone' => $t->order?->buyer_phone,
            'type' => $t->ticketType?->name,
            'order' => $t->order?->reference,
            // day => the time they were checked in that day
            'days' => $t->checkIns->mapWithKeys(fn (CheckIn $c) => [substr((string) $c->day, 0, 10) => $c->checked_in_at->setTimezone($tz)->format('g:i A')])->all(),
            'attended' => $t->checkIns->count(),
        ];
    }

    /**
     * The days to show: every day the event runs (first to last, in its own
     * timezone) plus any day someone was actually checked in, oldest first.
     * Capped at 120 days so an event dated a year long cannot build a
     * 365-column grid.
     *
     * @return list<string>
     */
    private function days(Event $event): array
    {
        $tz = $event->timezone ?: config('app.display_timezone', 'Asia/Kuala_Lumpur');
        $days = [];

        if ($event->starts_at) {
            $from = CarbonImmutable::parse($event->starts_at)->setTimezone($tz)->startOfDay();
            $to = CarbonImmutable::parse($event->ends_at ?? $event->starts_at)->setTimezone($tz)->startOfDay();

            for ($d = $from, $n = 0; $d->lte($to) && $n < 120; $d = $d->addDay(), $n++) {
                $days[] = $d->toDateString();
            }
        }

        $scanned = CheckIn::where('event_id', $event->id)->distinct()->orderBy('day')->limit(120)->pluck('day')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();

        $days = array_values(array_unique(array_merge($days, $scanned)));
        sort($days);

        return array_slice($days, -120);
    }

    /** @return array{q: string, day: string, presence: string, type: string} */
    private function filters(Request $request, array $days): array
    {
        $day = (string) $request->query('day', '');

        return [
            'q' => trim(mb_substr((string) $request->query('q', ''), 0, 100)),
            'day' => in_array($day, $days, true) ? $day : '',
            'presence' => in_array($request->query('presence'), ['present', 'absent'], true) ? (string) $request->query('presence') : 'present',
            'type' => (string) $request->query('type', ''),
        ];
    }

    /** Live tickets, narrowed by search, type and presence on a day. */
    private function query(Event $event, array $filters): Builder
    {
        $q = $filters['q'];

        return $event->tickets()
            ->whereIn('status', ['valid', 'checked_in'])
            ->when($filters['type'] !== '', fn ($w) => $w->where('ticket_type_id', (int) $filters['type']))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('attendee_name', 'like', "%{$q}%")
                ->orWhere('attendee_email', 'like', "%{$q}%")
                ->orWhere('qr_token', $q)
                ->orWhereHas('order', fn ($o) => $o->where('reference', 'like', "%{$q}%")
                    ->orWhere('buyer_name', 'like', "%{$q}%")
                    ->orWhere('buyer_email', 'like', "%{$q}%")
                    ->orWhere('buyer_phone', 'like', "%{$q}%"))))
            ->when($filters['day'] !== '', fn ($w) => $filters['presence'] === 'absent'
                ? $w->whereDoesntHave('checkIns', fn ($c) => $c->where('day', $filters['day']))
                : $w->whereHas('checkIns', fn ($c) => $c->where('day', $filters['day'])));
    }
}
