<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketType extends Model
{
    protected $fillable = [
        'event_id', 'name', 'description', 'kind', 'price', 'currency', 'quantity', 'sold',
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
