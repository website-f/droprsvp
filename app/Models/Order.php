<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'event_id', 'status', 'buyer_name', 'buyer_email', 'buyer_phone',
        'buyer_gender', 'buyer_age_band', 'buyer_city', 'buyer_source', 'notes',
        'subtotal', 'discount', 'discount_code_id', 'fees', 'tax', 'total', 'refunded_amount', 'currency', 'payment_ref', 'paid_at', 'refunded_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'fees' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Amount still refundable on a paid order (total minus what's already refunded). */
    public function remainingRefundable(): float
    {
        return max(0.0, round((float) $this->total - (float) $this->refunded_amount, 2));
    }

    /** Is there a refund request awaiting an organizer decision? */
    public function hasPendingRefund(): bool
    {
        return $this->refundRequests()->where('status', 'pending')->exists();
    }
}
