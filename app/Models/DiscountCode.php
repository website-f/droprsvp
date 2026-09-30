<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscountCode extends Model
{
    protected $fillable = [
        'event_id', 'code', 'kind', 'value', 'min_subtotal', 'max_redemptions', 'redemptions',
        'starts_at', 'ends_at', 'is_active', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_subtotal' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Codes the event page may advertise: flagged public, switched on, inside
     * their window, and not already used up.
     *
     * Deliberately the same conditions rejectionReason() enforces, minus the
     * minimum spend — a code with a minimum is still worth showing, it just
     * comes with a condition the page states alongside it. Showing a code that
     * would be refused for any of the OTHER reasons would be worse than showing
     * none: the buyer types it in and is told no.
     */
    public function scopeAdvertisable(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_public', true)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions', '<', 'max_redemptions'));
    }

    /** "20% off" / "RM10 off" — how the discount reads to a buyer. */
    public function offerLabel(): string
    {
        return $this->kind === 'fixed'
            ? 'RM'.rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').' off'
            : rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off';
    }

    /** Why this code can't be used on a given subtotal (null = it's good). */
    public function rejectionReason(float $subtotal): ?string
    {
        if (! $this->is_active) {
            return 'This code is no longer active.';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'This code isn’t available yet.';
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return 'This code has expired.';
        }
        if ($this->max_redemptions !== null && $this->redemptions >= $this->max_redemptions) {
            return 'This code has reached its redemption limit.';
        }
        if ($this->min_subtotal !== null && $subtotal < (float) $this->min_subtotal) {
            return 'Your order doesn’t meet this code’s minimum spend.';
        }

        return null;
    }

    public function isRedeemable(float $subtotal): bool
    {
        return $this->rejectionReason($subtotal) === null;
    }

    /** The discount (in currency) this code applies to a subtotal, capped at it. */
    public function discountFor(float $subtotal): float
    {
        $raw = $this->kind === 'fixed'
            ? (float) $this->value
            : $subtotal * (float) $this->value / 100;

        return round(min($raw, $subtotal), 2);
    }
}
