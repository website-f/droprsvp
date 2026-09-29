<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The internal copy of a platform event — a sign-up, an application, anything
 * the team should see without opening the admin panel. Raised by
 * App\Support\PlatformAlert, which also files the in-app notification.
 */
class PlatformAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  array<string,string>  $details  label => value rows */
    public function __construct(
        public string $heading,
        public ?string $body = null,
        public ?string $actionUrl = null,
        public array $details = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.platform-alert');
    }
}
