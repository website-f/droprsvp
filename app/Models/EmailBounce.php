<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bounce or complaint read from the return mailbox.
 *
 * hard      — the address does not exist; suppressed at once.
 * soft      — temporary (mailbox full, server busy); suppressed after repeats.
 * blocked   — the receiving server refused US (policy, reputation), not the
 *             address; never suppresses, but counts against the campaign.
 * complaint — the reader pressed "Report spam"; suppressed and unsubscribed.
 */
class EmailBounce extends Model
{
    public const TYPES = ['hard', 'soft', 'blocked', 'complaint'];

    protected $fillable = ['email', 'send_id', 'campaign_id', 'type', 'status_code', 'diagnostic', 'message_id'];

    public function send(): BelongsTo
    {
        return $this->belongsTo(EmailSend::class, 'send_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }
}
