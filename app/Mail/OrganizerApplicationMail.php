<?php

namespace App\Mail;

use App\Models\OrganizerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells an applicant their organizer/vendor application was approved or rejected. */
class OrganizerApplicationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public OrganizerProfile $profile, public bool $approved) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->approved
                ? 'You’re approved to host on '.config('app.name')
                : 'An update on your '.config('app.name').' application',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.organizer-application', with: [
            'name' => $this->profile->user?->name,
            'approved' => $this->approved,
            'reason' => $this->profile->review_reason,
        ]);
    }
}
