<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Someone booked your event" — the organizer's copy of a sale.
 *
 * The platform inbox has always been told (OrderPlacedAdminMail); the person
 * actually running the event was not, and only found out by opening their
 * dashboard. This is the same news written for them: who booked, what they
 * took, and what it earns after commission.
 *
 * Unlike the buyer's tickets this is a notification, not a receipt, so it
 * answers to the organizer_sales switch on Settings → Notifications.
 */
class OrderPlacedOrganizerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    /**
     * How many tickets this booking is for. The order lines are the record of
     * what was bought; the issued tickets are the fallback, so the subject can
     * never read "0 tickets booked".
     */
    public function tickets(): int
    {
        $lines = (int) $this->order->items->sum('quantity');

        return $lines > 0 ? $lines : (int) $this->order->tickets->count();
    }

    public function envelope(): Envelope
    {
        $tickets = $this->tickets();
        $event = $this->order->event?->title ?: 'your event';

        return new Envelope(
            subject: $tickets === 1
                ? '1 ticket booked · '.$event
                : $tickets.' tickets booked · '.$event,
            // Replying should reach the person who booked, not the robot.
            replyTo: $this->order->buyer_email
                ? [new Address($this->order->buyer_email, $this->order->buyer_name ?: 'Buyer')]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order-placed-organizer', with: [
            'order' => $this->order,
            'tickets' => $this->tickets(),
        ]);
    }
}
