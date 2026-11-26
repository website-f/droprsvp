<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Support\Ics;
use App\Support\Qr;
use App\Support\SeoManager;
use App\Support\Url;
use Inertia\Inertia;

class TicketController extends Controller
{
    /** The public ticket pass — the token in the URL is the credential. */
    public function show(Ticket $ticket)
    {
        $ticket->load(['event.user', 'ticketType', 'seatingTable']);
        $event = $ticket->event; // null if the event was since deleted/archived

        // The token is a credential — never index a ticket pass.
        app(SeoManager::class)->title('Your ticket')->noindex();

        return Inertia::render('public/ticket', [
            'ticket' => [
                'qr_token' => $ticket->qr_token,
                'attendee_name' => $ticket->attendee_name,
                'status' => $ticket->status,
                'type' => $ticket->ticketType?->name,
                'table' => $ticket->seatingTable?->name,
                'seat' => $ticket->seat_label,
                'organizer' => $event?->user?->name,
                'event' => $event ? [
                    'title' => $event->title,
                    'slug' => $event->slug,
                    'when' => $event->starts_at?->setTimezone($event->timezone)->format('D, j M Y · g:i A'),
                    'venue_name' => $event->venue_name,
                    'is_online' => $event->is_online,
                    'google_url' => $event->status === 'published' ? Ics::googleUrl($event) : null,
                    'ics_url' => $event->status === 'published' ? route('events.ics', ['region' => $event->region ?: Url::LOCALE, 'event' => $event]) : null,
                ] : [
                    'title' => 'Event no longer available',
                    'slug' => null,
                    'when' => null,
                    'venue_name' => null,
                    'is_online' => false,
                    'google_url' => null,
                    'ics_url' => null,
                ],
            ],
            'qr' => Qr::svg($ticket->qr_token),
            // One QR per person. Someone who bought three tickets holds three
            // passes, and each guest scans their own — so the pass page shows
            // all of them (this one first in the pager), not just the one link
            // they happened to open. Only passes still held by the same
            // person: a ticket transferred to a friend is theirs now.
            'passes' => $this->passesHeldWith($ticket),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function passesHeldWith(Ticket $ticket): array
    {
        $passes = $ticket->order_id
            ? Ticket::where('order_id', $ticket->order_id)
                ->where(fn ($q) => $ticket->attendee_email
                    ? $q->where('attendee_email', $ticket->attendee_email)
                    : $q->whereNull('attendee_email'))
                ->with(['ticketType:id,name', 'seatingTable:id,name'])
                ->orderBy('id')
                ->get()
            : collect([$ticket]);

        return $passes->map(fn (Ticket $t) => [
            'qr_token' => $t->qr_token,
            'attendee_name' => $t->attendee_name,
            'status' => $t->status,
            'type' => $t->ticketType?->name,
            'table' => $t->seatingTable?->name,
            'seat' => $t->seat_label,
            'qr' => Qr::svg($t->qr_token),
        ])->values()->all();
    }
}
