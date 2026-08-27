<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the buyer once an order settles — their tickets + pass links. */
class TicketsIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your tickets for '.$this->order->event->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tickets',
            with: [
                'order' => $this->order,
                'event' => $this->order->event,
                'tickets' => $this->order->tickets,
            ],
        );
    }
}
