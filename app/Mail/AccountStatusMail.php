<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Notifies a user when their account is disabled (suspended) or re-enabled. */
class AccountStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public bool $disabled, public ?string $reason = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->disabled
                ? 'Your '.config('app.name').' account has been disabled'
                : 'Your '.config('app.name').' account has been reactivated',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-status', with: [
            'name' => $this->user->name,
            'disabled' => $this->disabled,
            'reason' => $this->reason,
        ]);
    }
}
