<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use App\Support\AttendeeDirectory;
use App\Support\Cities;
use App\Support\CustomFields;
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
            ->with($this->rowRelations())
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Ticket $t) => $this->row($t, $event, $fields));

        return inertia('host/events/attendees', [
            'event' => ['title' => $event->title, 'slug' => $event->slug, 'mode' => $event->ticketing_mode],
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

        // Exactly what is on screen: the same search and the same facets.
        $tickets = $this->listing($event, $filters, $directory)
            ->with($this->rowRelations())
            ->orderByDesc('id')
            ->get();

        $filename = 'attendees-'.$event->slug.'.csv';

        return response()->streamDownload(function () use ($tickets, $event, $fields) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders names correctly
            // One extra column per booking question, in the order the organizer
            // defined them — a caterer wants the meal choices as a column they
            // can sort, not buried in a notes field.
            $questions = array_column($fields, 'label');

            fputcsv($out, array_merge(
                ['Name', 'Email', 'Phone', 'Gender', 'Age', 'Birth year', 'City', 'Heard via',
                    'Ticket type', 'Seat / Table', 'Order', 'Purchased', 'Status', 'Checked in at', 'Remarks', 'Token'],
                $questions,
            ));

            foreach ($tickets as $t) {
                $r = $this->row($t, $event, $fields);
                fputcsv($out, array_merge(
                    [$r['name'], $r['email'], $r['phone'], $r['buyer']['gender'], $r['buyer']['age_band'], $r['buyer']['birth_year'],
                        $r['buyer']['city'], $r['buyer']['source'], $r['type'], $r['seat'], $r['order_ref'], $r['purchased_at'],
                        $r['status'], $r['checked_in_at'], $r['buyer']['notes'], $r['token']],
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
            ->with($this->rowRelations())->first();

        if (! $ticket) {
            return response()->json(['result' => 'notfound', 'message' => 'No matching ticket for this event.']);
        }

        if (in_array($ticket->status, ['void', 'refunded'], true)) {
            return response()->json(['result' => 'invalid', 'message' => 'This ticket is '.$ticket->status.'.', 'ticket' => $this->row($ticket, $event)]);
        }

        if ($ticket->status === 'checked_in') {
            $when = $ticket->checked_in_at?->setTimezone($event->timezone)->format('g:i A');

            return response()->json([
                'result' => 'already',
                'message' => 'Already checked in'.($when ? " at {$when}" : '').'.',
                'ticket' => $this->row($ticket, $event),
            ]);
        }

        // Valid ticket. Auto-mode stamps it now; manual mode waits for a confirm tap.
        if ($request->boolean('auto')) {
            $ticket->update(['status' => 'checked_in', 'checked_in_at' => now(), 'checked_in_by' => $request->user()->id]);

            return response()->json([
                'result' => 'ok', 'message' => 'Checked in.',
                'ticket' => $this->row($ticket->refresh()->load($this->rowRelations()), $event), 'stats' => $this->stats($event),
            ]);
        }

        return response()->json(['result' => 'valid', 'message' => 'Valid ticket — confirm to check in.', 'ticket' => $this->row($ticket, $event)]);
    }

    /** Manually check in one ticket (from the detail view or the scan confirm button). */
    public function checkIn(Request $request, Event $event, int $ticket)
    {
        $this->authorize('update', $event);
        $model = $event->tickets()->with($this->rowRelations())->findOrFail($ticket);

        if (in_array($model->status, ['void', 'refunded'], true)) {
            return response()->json(['result' => 'invalid', 'message' => 'This ticket is '.$model->status.'.'], 422);
        }

        if ($model->status !== 'checked_in') {
            $model->update([
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_in_by' => $request->user()->id,
                'check_in_log' => $this->appendLog($model, 'in', $request->user()->id),
            ]);
        }

        return response()->json(['ticket' => $this->row($model->refresh()->load($this->rowRelations()), $event), 'stats' => $this->stats($event)]);
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
        $model = $event->tickets()->with($this->rowRelations())->findOrFail($ticket);

        if (in_array($model->status, ['void', 'refunded'], true)) {
            return response()->json(['result' => 'invalid', 'message' => 'This ticket is '.$model->status.'.'], 422);
        }

        if ($model->status !== 'checked_in') {
            return response()->json([
                'result' => 'invalid',
                'message' => 'This ticket is not checked in, so there is nothing to undo.',
            ], 422);
        }

        $model->update([
            'status' => 'valid',
            'checked_in_at' => null,
            'checked_in_by' => null,
            'check_in_log' => $this->appendLog($model, 'undo', $request->user()->id),
        ]);

        return response()->json(['ticket' => $this->row($model->refresh()->load($this->rowRelations()), $event), 'stats' => $this->stats($event)]);
    }

    /**
     * Append one entry to a ticket's check-in history.
     *
     * Capped, because a ticket toggled back and forth at a busy door should not
     * be able to grow its own row without bound. The oldest entries go first —
     * the first check-in and the most recent reversal are what anyone asks
     * about, and both survive a trim of the middle.
     *
     * @return array<int,array{action:string,at:string,by:int}>
     */
    private function appendLog(Ticket $ticket, string $action, int $userId): array
    {
        $log = $ticket->check_in_log ?? [];
        $log[] = ['action' => $action, 'at' => now()->toIso8601String(), 'by' => $userId];

        return array_slice($log, -20);
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
        return $event->tickets()
            ->when($filters['status'] !== 'all' && $filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
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
    private function rowRelations(): array
    {
        return [
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
            'status' => $t->status,
            'checked_in_at' => $t->checked_in_at?->setTimezone($event->timezone)->format('d M Y, g:i A'),
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

    /** @return array{total: int, checked_in: int} */
    private function stats(Event $event): array
    {
        return [
            'total' => $event->tickets()->whereIn('status', ['valid', 'checked_in'])->count(),
            'checked_in' => $event->tickets()->where('status', 'checked_in')->count(),
        ];
    }
}
