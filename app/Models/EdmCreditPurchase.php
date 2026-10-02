<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pack of email credits an organizer bought through CHIP. */
class EdmCreditPurchase extends Model
{
    protected $fillable = ['reference', 'organizer_id', 'pack', 'credits', 'amount', 'status', 'payment_ref', 'payment_method', 'payment_brand', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }
}
