<?php

namespace App\Mail;

use App\Models\OrganizerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when a vendor submits (or re-submits) their application for review. */
class OrganizerApplicationReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public OrganizerProfile $profile) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'We received your '.config('app.name').' vendor application');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.organizer-application-received', with: [
            'name' => $this->profile->user?->name,
            'business' => $this->profile->business_name,
        ]);
    }
}
