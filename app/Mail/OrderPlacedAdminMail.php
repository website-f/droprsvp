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
 * "A ticket just sold" — the internal copy, sent to the support inbox.
 *
 * The buyer has always received their tickets; nobody on the platform side was
 * told anything, so a sale was invisible until someone opened the admin panel.
 * Goes to the `support_email` setting, falling back to the from-address, which
 * is the same address the contact form already uses.
 */
class OrderPlacedAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        $event = $this->order->event?->title ?: 'an event';

        return new Envelope(
            subject: 'New order · '.$event.' · '.$this->order->currency.' '.number_format((float) $this->order->total, 2),
            // Replying to the notification should reach the buyer, not the robot.
            replyTo: $this->order->buyer_email
                ? [new Address($this->order->buyer_email, $this->order->buyer_name ?: 'Buyer')]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order-placed-admin', with: [
            'order' => $this->order,
        ]);
    }
}
