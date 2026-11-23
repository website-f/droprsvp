<?php

namespace App\Mail;

use App\Models\Event;
use App\Support\Url;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to a waitlisted person when the organizer invites them to buy. */
class WaitlistInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event, public string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A spot just opened for '.$this->event->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.waitlist-invite',
            with: [
                'event' => $this->event,
                'name' => $this->name,
                'url' => Url::in($this->event->region ?: Url::LOCALE, 'e', $this->event->slug).'#tickets',
            ],
        );
    }
}
