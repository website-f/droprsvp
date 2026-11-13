<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\CheckInService;
use App\Support\AttendeeDirectory;
use App\Support\Cities;
use App\Support\CustomFields;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Visitor / admission management for an event: a searchable, filterable,
 * exportable list of every ticket-holder, a per-admission detail view, and the
 * door scanner (fullscreen camera → check in) — all in one console.
 */
class AttendeeController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $filters = $this->filters($request);
        $directory = $this->directory($event, $filters);
        $fields = $directory->fields();

        $tickets = $this->listing($event, $filters, $directory)
            ->with($this->rowRelations($event))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Ticket $t) => $this->row($t, $event, $fields));

        return inertia('host/events/attendees', [
            'event' => [
                'title' => $event->title, 'slug' => $event->slug, 'mode' => $event->ticketing_mode,
                // Daily check-in: "checked in" on this page means checked in TODAY.
                'daily' => (bool) $event->daily_checkin,
                'today' => CarbonImmutable::parse($this->door()->today($event))->format('D, j M'),
            ],
            'tickets' => $tickets,
            'filters' => $filters,
            'ticketTypes' => $event->ticketTypes()->orderBy('sort_order')->get(['id', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            // Filters built from the event itself — its booking questions and
            // the checkout demographics buyers actually filled in — each with a
            // live count. See App\Support\AttendeeDirectory.
            'facets' => $directory->facets(),
            'stats' => $this->stats($event),
            // Arriving from the "Check-in (scan)" shortcut opens the scanner immediately.
            'openScanner' => $request->boolean('scan'),
        ]);
    }

    /** CSV of the current (filtered) admission list — one row per ticket. */
    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->authorize('update', $event);

        $filters = $this->filters($request);
        $directory = $this->directory($event, $filters);
        $fields = $directory->fields();

        // Exactly what is on screen: the same search and the same facets —
        // read 500 at a time, so a big event never sits in memory at once.
        $tickets = $this->listing($event, $filters, $directory)
            ->with($this->rowRelations($event))
            ->withCount('checkIns')
            ->lazyByIdDesc(500);
        $daily = (bool) $event->daily_checkin;

        $filename = 'attendees-'.$event->slug.'.csv';

        return response()->streamDownload(function () use ($tickets, $event, $fields, $daily) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders names correctly
            // One extra column per booking question, in the order the organizer
            // defined them — a caterer wants the meal choices as a column they
            // can sort, not buried in a notes field.
            $questions = array_column($fields, 'label');

            fputcsv($out, array_merge(
                ['Name', 'Email', 'Phone', 'Gender', 'Age', 'Birth year', 'City', 'Heard via',
                    'Ticket type', 'Seat / Table', 'Order', 'Purchased', $daily ? 'Today' : 'Status', 'Checked in at', 'Remarks', 'Token'],
                $daily ? ['Days attended'] : [],
                $questions,
            ));

            foreach ($tickets as $t) {
                $r = $this->row($t, $event, $fields);
                fputcsv($out, array_merge(
                    [$r['name'], $r['email'], $r['phone'], $r['buyer']['gender'], $r['buyer']['age_band'], $r['buyer']['birth_year'],
                        $r['buyer']['city'], $r['buyer']['source'], $r['type'], $r['seat'], $r['order_ref'], $r['purchased_at'],
                        $r['status'], $r['checked_in_at'], $r['buyer']['notes'], $r['token']],
                    $daily ? [(int) $t->check_ins_count] : [],
                    array_map(fn (string $q) => $r['answers'][$q] ?? '', $questions),
                ));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Door scan. Accepts a raw token or a full pass URL. When `auto` is set a valid
     * ticket is checked in immediately; otherwise the ticket is only looked up so the
     * operator can confirm. Re-scanning an already-checked-in ticket is reported, not
     * re-stamped.
     */
    public function scan(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'auto' => ['nullable', 'boolean'],
        ]);

        $token = basename(trim($data['token'])); // a scanned pass URL reduces to its token
        $ticket = $event->tickets()->where('qr_token', $token)
            ->with($this->rowRelations($event))->first();

        if (! $ticket) {
            return response()->json(['result' => 'notfound', 'message' => 'No matching ticket for this event.']);
        }

        // Only a live ticket gets in (valid or checked in) — refunded, void and
        // cancelled ones are turned away.
        if (! in_array($ticket->status, ['valid', 'checked_in'], true)) {
            return response()->json(['result' => 'invalid', 'message' => 'This ticket is '.$ticket->status.'.', 'ticket' => $this->row($ticket, $event)]);
        }

        // Auto-mode checks in now; manual mode only looks the ticket up so the
        // operator can confirm — unless it is already in (for today).
        if ($request->boolean('auto') || $this->door()->isIn($event, $ticket)) {
            $result = $request->boolean('auto')
                ? $this->door()->checkIn($event, $ticket, $request->user()->id, 'scan')
                : ['result' => 'already', 'message' => $event->daily_checkin ? 'Already checked in today.' : 'Already checked in.'];

            return response()->json([
                ...$result,
                'ticket' => $this->row($ticket->refresh()->load($this->rowRelations($event)), $event),
                'stats' => $this->stats($event),
            ]);
        }

        return response()->json(['result' => 'valid', 'message' => 'Valid ticket — confirm to check in.', 'ticket' => $this->row($ticket, $event)]);
    }

    /** Manually check in one ticket (from the detail view or the scan confirm button). */
    public function checkIn(Request $request, Event $event, int $ticket)
    {
        $this->authorize('update', $event);
        $model = $event->tickets()->with($this->rowRelations($event))->findOrFail($ticket);

        // Checking in someone already in is a quiet no-op, as before.
        $result = $this->door()->checkIn($event, $model, $request->user()->id, 'manual');

        if ($result['result'] === 'invalid') {
            return response()->json($result, 422);
        }

        return response()->json(['ticket' => $this->row($model->refresh()->load($this->rowRelations($event)), $event), 'stats' => $this->stats($event)]);
    }

    /**
     * Reverse an accidental check-in.
     *
     * Guarded, because at a door this is one tap next to the tap everybody is
     * making, and getting it wrong lets someone through twice.
     *
     *  - It must be an undo of something. Reversing a ticket that is not
     *    checked in is a mistake, not a no-op, so it says so rather than
     *    returning 200 and letting the tapper believe they did something.
     *  - A void or refunded ticket is not reinstated here. That is a refund
     *    decision, not a door decision.
     *  - The check-in is RECORDED, not erased. This used to null out
     *    checked_in_at and checked_in_by, which destroyed the only evidence
     *    that the ticket had ever been scanned and by whom.
     */
    public function undo(Request $request, Event $event, int $ticket)
    {
        $this->authorize('update', $event);
        $model = $event->tickets()->with($this->rowRelations($event))->findOrFail($ticket);

        $result = $this->door()->undo($event, $model, $request->user()->id);

        if ($result['result'] === 'invalid') {
            return response()->json($result, 422);
        }

        return response()->json(['ticket' => $this->row($model->refresh()->load($this->rowRelations($event)), $event), 'stats' => $this->stats($event)]);
    }

    private function door(): CheckInService
    {
        return app(CheckInService::class);
    }

    /** Today's check-in row, from the relation rowRelations() loads on a daily event. */
    private function todaysCheckIn(Ticket $t): ?CheckIn
    {
        return $t->relationLoaded('checkIns') ? $t->checkIns->first() : null;
    }

    /**
     * @return array{q: string, status: string, type: string, facets: array<string, string>}
     */
    private function filters(Request $request): array
    {
        // facets[f_<fieldId>]=<optionId>, facets[gender]=female, … — one value
        // per facet. Unknown keys are dropped later by AttendeeDirectory, which
        // is the only thing that knows what this event's facets are.
        $facets = collect((array) $request->query('facets', []))
            ->filter(fn ($value, $key) => is_string($key) && is_scalar($value) && (string) $value !== '')
            ->map(fn ($value) => Str::limit((string) $value, 120, ''))
            ->all();

        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', 'all'),
            'type' => (string) $request->query('type', 'all'),
            'facets' => $facets,
        ];
    }

    /**
     * The SQL half of the filtering: status and ticket type. Search and facets
     * are applied by AttendeeDirectory, because they reach into the JSON answers.
     *
     * @param  array{q: string, status: string, type: string, facets: array<string, string>}  $filters
     */
    private function query(Event $event, array $filters): Builder
    {
        $today = $event->daily_checkin ? $this->door()->today($event) : null;

        return $event->tickets()
            ->when($filters['status'] !== 'all' && $filters['status'] !== '', function (Builder $q) use ($filters, $today) {
                // On a daily event "checked in" means checked in today.
                if ($today && $filters['status'] === 'checked_in') {
                    return $q->whereIn('status', ['valid', 'checked_in'])->whereHas('checkIns', fn ($c) => $c->where('day', $today));
                }

                if ($today && $filters['status'] === 'valid') {
                    return $q->whereIn('status', ['valid', 'checked_in'])->whereDoesntHave('checkIns', fn ($c) => $c->where('day', $today));
                }

                return $q->where('status', $filters['status']);
            })
            ->when($filters['type'] !== 'all' && $filters['type'] !== '', fn (Builder $q) => $q->where('ticket_type_id', $filters['type']));
    }

    private function directory(Event $event, array $filters): AttendeeDirectory
    {
        return new AttendeeDirectory($event, $this->query($event, $filters), $filters['q'], $filters['facets']);
    }

    /** The list to page through: the SQL filters, narrowed to the directory's matches. */
    private function listing(Event $event, array $filters, AttendeeDirectory $directory): Builder
    {
        $query = $this->query($event, $filters);

        return $directory->narrows()
            ? $query->whereIn('id', $directory->matchingIds() ?: [0])
            : $query;
    }

    /** What row() reads, loaded up front so a page of 25 is not 25 × N queries. */
    private function rowRelations(Event $event): array
    {
        $today = $event->daily_checkin ? $this->door()->today($event) : null;

        return [
            // Today's check-in, on a daily event — what "checked in" means there.
            ...($today ? ['checkIns' => fn ($q) => $q->where('day', $today)] : []),
            'ticketType:id,name',
            'seatingTable:id,name',
            'order' => fn ($q) => $q
                ->select([
                    'id', 'reference', 'buyer_name', 'buyer_email', 'buyer_phone', 'buyer_gender', 'buyer_age_band',
                    'buyer_birth_year', 'buyer_city', 'buyer_source', 'notes', 'total', 'discount', 'discount_code_id',
                    'currency', 'paid_at',
                ])
                ->withCount('tickets'),
            'order.discountCode:id,code',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>|null  $fields  the event's booking
     *                                                       fields, resolved once by the caller so 25 rows don't re-parse them
     */
    private function row(Ticket $t, Event $event, ?array $fields = null): array
    {
        $fields ??= CustomFields::forEvent($event);
        $order = $t->order;
        $answers = CustomFields::readable($event, $t->custom_answers, $fields);
        $birthYear = $order?->buyer_birth_year ? (int) $order->buyer_birth_year : null;

        return [
            'id' => $t->id,
            'token' => $t->qr_token,
            'name' => $t->attendee_name ?: ($order?->buyer_name ?: 'Guest'),
            'email' => $t->attendee_email ?: $order?->buyer_email,
            'phone' => $order?->buyer_phone,
            'type' => $t->ticketType?->name,
            'seat' => $t->seat_label ?: $t->seatingTable?->name,
            'order_ref' => $order?->reference,
            'purchased_at' => $order?->paid_at?->setTimezone($event->timezone)->format('d M Y, g:i A'),
            // On a daily event, status and time are TODAY's: in or not yet.
            'status' => $event->daily_checkin && in_array($t->status, ['valid', 'checked_in'], true)
                ? ($this->todaysCheckIn($t) ? 'checked_in' : 'valid')
                : $t->status,
            'checked_in_at' => ($event->daily_checkin ? $this->todaysCheckIn($t)?->checked_in_at : $t->checked_in_at)
                ?->setTimezone($event->timezone)->format('d M Y, g:i A'),
            // The organizer's own questions, answered per ticket at checkout, as
            // readable label => answer pairs. Resolved against the event's CURRENT
            // definitions, so a renamed question shows its new wording and a
            // deleted one drops out rather than showing a bare id.
            'answers' => $answers,
            // EVERY question, in the organizer's order, answered or not — the
            // detail view shows a blank as a blank instead of silently omitting
            // the question, which read as "they weren't asked".
            'questions' => array_map(fn (array $field) => [
                'label' => $field['label'],
                'answer' => $answers[$field['label']] ?? null,
            ], $fields),
            // Everything else checkout asked. Without these the organizer saw a
            // name and an email and nothing else about who was coming.
            'buyer' => [
                'gender' => AttendeeDirectory::genderLabel($order?->buyer_gender),
                'age_band' => $order?->buyer_age_band,
                'birth_year' => $birthYear,
                'age' => $birthYear ? (int) now()->format('Y') - $birthYear : null,
                'city' => $order?->buyer_city,
                'state' => $order?->buyer_city ? Cities::stateForCity($order->buyer_city) : null,
                'source' => AttendeeDirectory::sourceLabel($order?->buyer_source),
                'notes' => $order?->notes,
            ],
            'order' => [
                'tickets' => (int) ($order?->tickets_count ?? 1),
                'total' => $order ? (float) $order->total : null,
                'discount' => $order ? (float) $order->discount : null,
                'code' => $order?->discountCode?->code,
                'currency' => $order?->currency ?? 'MYR',
            ],
        ];
    }

    /**
     * The numbers above the list.
     *
     * `by_type` answers "how many Ticket A, how many Ticket B" without making
     * the organizer filter to one type, count, then filter to the next. It is
     * counted off the tickets themselves rather than ticket_types.sold, which
     * records units bought at checkout and drifts from reality once a ticket
     * is voided — the split has to add up to the Admissions figure beside it.
     *
     * Deliberately ignores the active filters, like the totals beside it: the
     * point is the whole split, always on screen.
     *
     * @return array{total: int, checked_in: int, by_type: list<array{id: int|null, name: string, total: int, checked_in: int}>}
     */
    private function stats(Event $event): array
    {
        // A daily event counts who is in TODAY; otherwise who has ever been in.
        $today = $event->daily_checkin ? $this->door()->today($event) : null;
        $inToday = fn ($q) => $q->select('ticket_id')->from('check_ins')->where('event_id', $event->id)->where('day', $today);

        $counts = $event->tickets()
            ->whereIn('status', ['valid', 'checked_in'])
            ->when($today,
                fn ($q) => $q->selectRaw('ticket_type_id, count(*) as total, sum(case when id in (select ticket_id from check_ins where event_id = ? and day = ?) then 1 else 0 end) as checked_in', [$event->id, $today]),
                fn ($q) => $q->selectRaw('ticket_type_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as checked_in', ['checked_in']))
            ->groupBy('ticket_type_id')
            ->get()
            ->keyBy('ticket_type_id');

        // Every type the event sells, in the organizer's own order, including
        // the ones nobody has bought yet.
        $byType = $event->ticketTypes()->orderBy('sort_order')->get(['id', 'name'])
            ->map(fn (TicketType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'total' => (int) ($counts[$type->id]->total ?? 0),
                'checked_in' => (int) ($counts[$type->id]->checked_in ?? 0),
            ])
            ->values()
            ->all();

        // Tickets whose type has since been deleted still admit someone, so
        // they are gathered here instead of leaving the split short.
        $known = array_column($byType, 'id');
        $orphans = $counts->reject(fn ($row) => in_array($row->ticket_type_id, $known, true));

        if ($orphans->isNotEmpty()) {
            $byType[] = [
                'id' => null,
                'name' => 'Other',
                'total' => (int) $orphans->sum('total'),
                'checked_in' => (int) $orphans->sum('checked_in'),
            ];
        }

        return [
            'total' => $event->tickets()->whereIn('status', ['valid', 'checked_in'])->count(),
            'checked_in' => $today
                ? $event->tickets()->whereIn('status', ['valid', 'checked_in'])->whereIn('id', $inToday)->count()
                : $event->tickets()->where('status', 'checked_in')->count(),
            'by_type' => $byType,
        ];
    }
}
