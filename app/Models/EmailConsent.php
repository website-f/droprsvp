<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address's marketing decision within one scope. See App\Support\Edm\Consent,
 * which is the only thing that should write these.
 */
class EmailConsent extends Model
{
    protected $fillable = [
        'email', 'scope', 'organizer_id', 'user_id', 'status', 'source',
        'consented_at', 'unsubscribed_at', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }
}
