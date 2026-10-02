<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organizer's own From domain. The DKIM private key is encrypted at rest
 * (the "encrypted" cast) and never leaves the server; only the public half is
 * shown, for the organizer to publish in DNS.
 */
class EdmSendingDomain extends Model
{
    protected $fillable = ['organizer_id', 'domain', 'selector', 'dkim_private', 'dkim_public', 'verify_token', 'status', 'checks', 'last_checked_at', 'verified_at'];

    protected $hidden = ['dkim_private'];

    protected function casts(): array
    {
        return [
            'dkim_private' => 'encrypted',
            'checks' => 'array',
            'last_checked_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }
}
