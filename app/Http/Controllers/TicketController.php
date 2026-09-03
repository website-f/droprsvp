<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Support\Qr;
use App\Support\SeoManager;
use Inertia\Inertia;

class TicketController extends Controller
{
    /** The public ticket pass — the token in the URL is the credential. */
    public function show(Ticket $ticket)
    {
        $ticket->load(['event.user', 'ticketType', 'seatingTable']);
        $event = $ticket->event;

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
                'organizer' => $event->user?->name,
                'event' => [
                    'title' => $event->title,
                    'slug' => $event->slug,
                    'when' => $event->starts_at?->setTimezone($event->timezone)->format('D, j M Y · g:i A'),
                    'venue_name' => $event->venue_name,
                    'is_online' => $event->is_online,
                    'google_url' => $event->status === 'published' ? \App\Support\Ics::googleUrl($event) : null,
                    'ics_url' => $event->status === 'published' ? route('events.ics', $event) : null,
                ],
            ],
            'qr' => Qr::svg($ticket->qr_token),
        ]);
    }
}
