<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Support\Qr;
use Inertia\Inertia;

class TicketController extends Controller
{
    /** The public ticket pass — the token in the URL is the credential. */
    public function show(Ticket $ticket)
    {
        $ticket->load(['event', 'ticketType']);
        $event = $ticket->event;

        return Inertia::render('public/ticket', [
            'ticket' => [
                'qr_token' => $ticket->qr_token,
                'attendee_name' => $ticket->attendee_name,
                'status' => $ticket->status,
                'type' => $ticket->ticketType?->name,
                'event' => [
                    'title' => $event->title,
                    'slug' => $event->slug,
                    'when' => optional($event->starts_at)?->setTimezone($event->timezone)->format('D, j M Y · g:i A'),
                    'venue_name' => $event->venue_name,
                    'is_online' => $event->is_online,
                ],
            ],
            'qr' => Qr::svg($ticket->qr_token),
        ]);
    }
}
