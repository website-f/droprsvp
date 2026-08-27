<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    /** The door check-in console for an event. */
    public function index(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        return inertia('host/events/checkin', [
            'event' => ['title' => $event->title, 'slug' => $event->slug],
            'stats' => $this->stats($event),
            'recent' => $event->tickets()
                ->where('status', 'checked_in')
                ->latest('checked_in_at')
                ->limit(12)
                ->get(['attendee_name', 'checked_in_at'])
                ->map(fn ($t) => [
                    'name' => $t->attendee_name ?: 'Guest',
                    'at' => optional($t->checked_in_at)->setTimezone($event->timezone)->format('g:i A'),
                ]),
            'scan' => $request->session()->get('scan'),
        ]);
    }

    /** Verify a scanned/entered token and admit the guest. */
    public function scan(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $token = trim((string) $request->validate(['token' => ['required', 'string']])['token']);
        // Tolerate a scanned pass URL by taking the last path segment.
        $token = trim(basename($token));

        $ticket = $event->tickets()->where('qr_token', $token)->first();

        if (! $ticket) {
            $result = ['ok' => false, 'message' => 'Ticket not found for this event.'];
        } elseif (in_array($ticket->status, ['void', 'refunded'], true)) {
            $result = ['ok' => false, 'name' => $ticket->attendee_name, 'message' => "Ticket is not valid ({$ticket->status})."];
        } elseif ($ticket->status === 'checked_in') {
            $result = ['ok' => false, 'already' => true, 'name' => $ticket->attendee_name, 'message' => 'Already checked in at '.optional($ticket->checked_in_at)->setTimezone($event->timezone)->format('g:i A').'.'];
        } else {
            $ticket->update(['status' => 'checked_in', 'checked_in_at' => now(), 'checked_in_by' => $request->user()->id]);
            $result = ['ok' => true, 'name' => $ticket->attendee_name ?: 'Guest', 'message' => 'Checked in.'];
        }

        return redirect()->route('host.events.checkin', $event)->with('scan', $result);
    }

    /** @return array{total:int,checked_in:int} */
    private function stats(Event $event): array
    {
        return [
            'total' => $event->tickets()->whereIn('status', ['valid', 'checked_in'])->count(),
            'checked_in' => $event->tickets()->where('status', 'checked_in')->count(),
        ];
    }
}
