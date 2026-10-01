<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An email campaign. See App\Services\Edm\CampaignSender for its lifecycle. */
class EmailCampaign extends Model
{
    public const STATUSES = ['draft', 'scheduled', 'sending', 'paused', 'sent', 'cancelled'];

    /**
     * Mirrors the column defaults. The database fills these on insert, but a
     * freshly created model never reads them back, so without this a new
     * campaign's status is null in memory until it is reloaded.
     */
    protected $attributes = [
        'status' => 'draft',
        'kind' => 'standard',
    ];

    protected $fillable = [
        'organizer_id', 'kind', 'name', 'subject', 'preheader', 'from_name', 'reply_to',
        'design', 'audience', 'html', 'text', 'status', 'paused_reason',
        'scheduled_at', 'started_at', 'finished_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'design' => 'array',
            'audience' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function sends(): HasMany
    {
        return $this->hasMany(EmailSend::class, 'campaign_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(EmailLink::class, 'campaign_id');
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Still being written: the only state in which its content may change. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }
}
