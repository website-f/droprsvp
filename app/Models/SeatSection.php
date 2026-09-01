<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeatSection extends Model
{
    protected $fillable = [
        'event_id', 'ticket_type_id', 'name', 'color', 'kind',
        'price', 'currency', 'rows', 'cols', 'capacity', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function isSeated(): bool
    {
        return $this->kind === 'seated';
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class)->orderBy('sort_order');
    }
}
