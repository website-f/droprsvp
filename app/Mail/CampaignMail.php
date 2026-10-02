<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * One recipient's copy of a campaign, already personalised.
 *
 * The headers are the part that matters for deliverability:
 *
 *  - List-Unsubscribe + List-Unsubscribe-Post (RFC 8058). Gmail and Yahoo
 *    require one-click unsubscribe from bulk senders, and show it as an
 *    "Unsubscribe" button beside the sender name. A reader who can leave in one
 *    click does that instead of pressing "Report spam" — and spam reports are
 *    the number inbox placement is judged on.
 *  - Precedence: bulk and Auto-Submitted, so out-of-office replies stay out of
 *    the reply-to inbox.
 */
class CampaignMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public string $textBody,
        public string $unsubscribeUrl,
        public string $fromAddress,
        public string $fromName,
        public ?string $replyToAddress = null,
        public ?string $campaignTag = null,
        public ?string $sendToken = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
            subject: $this->subjectLine,
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: array_filter([
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'Precedence' => 'bulk',
            'Auto-Submitted' => 'auto-generated',
            // Lets a bounce or complaint be traced to its campaign.
            'X-Campaign' => $this->campaignTag,
            // The recipient's send, so a bounce quoting these headers is tied
            // to the exact send rather than guessed from the address.
            'X-Edm-Send' => $this->sendToken,
        ]));
    }

    public function build(): static
    {
        return $this->html($this->htmlBody)->text('edm.plain', ['body' => $this->textBody]);
    }
}
