<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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

        $tickets = $this->query($event, $filters)
            ->with(['ticketType:id,name', 'order:id,reference,buyer_name,buyer_email,buyer_phone,paid_at', 'seatingTable:id,name'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Ticket $t) => $this->row($t, $event));

        return inertia('host/events/attendees', [
            'event' => ['title' => $event->title, 'slug' => $event->slug],
            'tickets' => $tickets,
            'filters' => $filters,
            'ticketTypes' => $event->ticketTypes()->orderBy('sort_order')->get(['id', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'stats' => $this->stats($event),
        ]);
    }

    /** CSV of the current (filtered) admission list — one row per ticket. */
    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->authorize('update', $event);

        $tickets = $this->query($event, $this->filters($request))
            ->with(['ticketType:id,name', 'order:id,reference,buyer_name,buyer_email,buyer_phone,paid_at', 'seatingTable:id,name'])
            ->orderByDesc('id')->get();

        $filename = 'attendees-'.$event->slug.'.csv';

        return response()->streamDownload(function () use ($tickets, $event) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders names correctly
            fputcsv($out, ['Name', 'Email', 'Phone', 'Ticket type', 'Seat / Table', 'Order', 'Purchased', 'Status', 'Checked in at', 'Token']);
            foreach ($tickets as $t) {
                $r = $this->row($t, $event);
                fputcsv($out, [$r['name'], $r['email'], $r['phone'], $r['type'], $r['seat'], $r['order_ref'], $r['purchased_at'], $r['status'], $r['checked_in_at'], $r['token']]);
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
            ->with(['ticketType:id,name', 'order:id,reference,buyer_name,buyer_email,buyer_phone,paid_at', 'seatingTable:id,name'])->first();

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
                'ticket' => $this->row($ticket->refresh(), $event), 'stats' => $this->stats($event),
            ]);
        }

        return response()->json(['result' => 'valid', 'message' => 'Valid ticket — confirm to check in.', 'ticket' => $this->row($ticket, $event)]);
    }

    /** Manually check in one ticket (from the detail view or the scan confirm button). */
    public function checkIn(Request $request, Event $event, int $ticket)
    {
        $this->authorize('update', $event);
        $model = $event->tickets()->findOrFail($ticket);

        if (in_array($model->status, ['void', 'refunded'], true)) {
            return response()->json(['result' => 'invalid', 'message' => 'This ticket is '.$model->status.'.'], 422);
        }

        if ($model->status !== 'checked_in') {
            $model->update(['status' => 'checked_in', 'checked_in_at' => now(), 'checked_in_by' => $request->user()->id]);
        }

        return response()->json(['ticket' => $this->row($model->refresh(), $event), 'stats' => $this->stats($event)]);
    }

    /** Reverse an accidental check-in. */
    public function undo(Request $request, Event $event, int $ticket)
    {
        $this->authorize('update', $event);
        $model = $event->tickets()->findOrFail($ticket);

        if ($model->status === 'checked_in') {
            $model->update(['status' => 'valid', 'checked_in_at' => null, 'checked_in_by' => null]);
        }

        return response()->json(['ticket' => $this->row($model->refresh(), $event), 'stats' => $this->stats($event)]);
    }

    /** @return array{q: string, status: string, type: string} */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', 'all'),
            'type' => (string) $request->query('type', 'all'),
        ];
    }

    /** @param  array{q: string, status: string, type: string}  $filters */
    private function query(Event $event, array $filters): Builder
    {
        return $event->tickets()
            ->when($filters['status'] !== 'all' && $filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['type'] !== 'all' && $filters['type'] !== '', fn (Builder $q) => $q->where('ticket_type_id', $filters['type']))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $s = $filters['q'];
                $q->where(function (Builder $w) use ($s) {
                    $w->where('attendee_name', 'like', "%{$s}%")
                        ->orWhere('attendee_email', 'like', "%{$s}%")
                        ->orWhere('qr_token', 'like', "%{$s}%")
                        ->orWhereHas('order', fn (Builder $o) => $o
                            ->where('reference', 'like', "%{$s}%")
                            ->orWhere('buyer_name', 'like', "%{$s}%")
                            ->orWhere('buyer_email', 'like', "%{$s}%"));
                });
            });
    }

    private function row(Ticket $t, Event $event): array
    {
        return [
            'id' => $t->id,
            'token' => $t->qr_token,
            'name' => $t->attendee_name ?: ($t->order?->buyer_name ?: 'Guest'),
            'email' => $t->attendee_email ?: $t->order?->buyer_email,
            'phone' => $t->order?->buyer_phone,
            'type' => $t->ticketType?->name,
            'seat' => $t->seat_label ?: $t->seatingTable?->name,
            'order_ref' => $t->order?->reference,
            'purchased_at' => $t->order?->paid_at?->setTimezone($event->timezone)->format('d M Y, g:i A'),
            'status' => $t->status,
            'checked_in_at' => $t->checked_in_at?->setTimezone($event->timezone)->format('d M Y, g:i A'),
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
