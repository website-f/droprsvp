<?php

namespace App\Mail;

use App\Models\Payout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Notifies an organizer that a payout has been paid out. */
class PayoutPaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payout $payout) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' payout has been paid');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payout-paid', with: ['payout' => $this->payout]);
    }
}
