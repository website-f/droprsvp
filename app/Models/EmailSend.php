<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One recipient's copy of one campaign — and its place in the send queue. */
class EmailSend extends Model
{
    /** Mirrors the column defaults; see EmailCampaign. */
    protected $attributes = [
        'status' => 'queued',
        'attempts' => 0,
        'open_count' => 0,
        'click_count' => 0,
    ];

    protected $fillable = [
        'campaign_id', 'user_id', 'email', 'name', 'token', 'status', 'attempts', 'error',
        'sent_at', 'opened_at', 'open_count', 'clicked_at', 'click_count', 'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
