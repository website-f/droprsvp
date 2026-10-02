<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You have unread messages" — one digest per person, never one email per
 * message. Sent by `chat:notify` to people who have been away for a while.
 * Shows who wrote and a short preview, never the full text: the message lives
 * in the app.
 */
class ChatUnreadMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  list<array{name: string, preview: string, count: int}>  $threads */
    public function __construct(
        public string $recipientName,
        public int $total,
        public array $threads,
    ) {}

    public function envelope(): Envelope
    {
        $first = $this->threads[0]['name'] ?? 'someone';
        $others = count($this->threads) - 1;

        return new Envelope(subject: $others > 0
            ? "New messages from {$first} and {$others} other".($others === 1 ? '' : 's')
            : "New message from {$first}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.chat-unread');
    }
}
