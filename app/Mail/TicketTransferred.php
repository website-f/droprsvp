<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the new attendee when a buyer transfers a ticket to them. */
class TicketTransferred extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket, public ?string $fromName = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You’ve received a ticket for '.$this->ticket->event->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-transferred',
            with: [
                'ticket' => $this->ticket,
                'event' => $this->ticket->event,
                'fromName' => $this->fromName,
                'url' => url('/tickets/'.$this->ticket->qr_token),
            ],
        );
    }
}
