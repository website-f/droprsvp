<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeatingTable extends Model
{
    protected $fillable = ['event_id', 'name', 'capacity', 'sort_order'];

    protected $attributes = ['capacity' => 8, 'sort_order' => 0];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'seating_table_id');
    }

    public function seatsLeft(): int
    {
        return max(0, $this->capacity - $this->tickets()->count());
    }
}
