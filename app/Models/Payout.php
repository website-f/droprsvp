<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $fillable = ['user_id', 'reference', 'amount', 'currency', 'status', 'method', 'chip_send_id', 'chip_send_state', 'note', 'requested_at', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'requested_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
