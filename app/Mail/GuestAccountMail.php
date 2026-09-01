<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to a guest buyer whose account was auto-created — carries a temp password. */
class GuestAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $tempPassword) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' account — sign in to view your tickets');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.guest-account', with: [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'password' => $this->tempPassword,
        ]);
    }
}
