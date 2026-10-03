<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An organizer's standing in EDM: active, or suspended pending review. */
class EdmAccount extends Model
{
    protected $attributes = ['status' => 'active', 'access' => 'inherit'];

    protected $fillable = ['organizer_id', 'status', 'access', 'suspended_reason', 'suspended_at', 'reviewed_by', 'reviewed_at', 'monthly_allowance', 'rules'];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime', 'reviewed_at' => 'datetime', 'monthly_allowance' => 'integer', 'rules' => 'array'];
    }

    public static function for(int $organizerId): self
    {
        return self::firstOrCreate(['organizer_id' => $organizerId]);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }
}
