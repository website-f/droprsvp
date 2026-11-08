<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketType extends Model
{
    protected $fillable = [
        'event_id', 'seat_section_id', 'name', 'description', 'kind', 'price', 'compare_at_price', 'group_min', 'group_price', 'currency', 'quantity', 'sold',
        'min_per_order', 'max_per_order', 'sales_start', 'sales_end', 'is_active', 'sort_order',
    ];

    /** In-memory defaults so new instances behave correctly before a DB reload. */
    protected $attributes = [
        'kind' => 'paid',
        'currency' => 'MYR',
        'sold' => 0,
        'min_per_order' => 1,
        'max_per_order' => 10,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'group_min' => 'integer',
            'group_price' => 'decimal:2',
            'is_active' => 'boolean',
            'sales_start' => 'datetime',
            'sales_end' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Whether buying enough at once earns the group price. */
    public function hasGroupPrice(): bool
    {
        return $this->kind === 'paid' && $this->group_min !== null && $this->group_min > 1 && $this->group_price !== null;
    }

    /**
     * The price of each ticket when buying `$quantity` at once: the group price
     * from group_min tickets up, the normal price below it, nothing when free.
     */
    public function unitPriceFor(int $quantity): float
    {
        if ($this->kind === 'free') {
            return 0.0;
        }

        return $this->hasGroupPrice() && $quantity >= $this->group_min ? (float) $this->group_price : (float) $this->price;
    }

    /** Units still available (null quantity = unlimited). */
    public function remaining(): ?int
    {
        return $this->quantity === null ? null : max(0, $this->quantity - $this->sold);
    }

    /** On sale right now? (active, within window, not sold out). */
    public function isOnSale(): bool
    {
        $now = now();
        if (! $this->is_active) {
            return false;
        }
        if ($this->sales_start && $now->lt($this->sales_start)) {
            return false;
        }
        if ($this->sales_end && $now->gt($this->sales_end)) {
            return false;
        }
        $left = $this->remaining();

        return $left === null || $left > 0;
    }
}
