<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeatingTable extends Model
{
    protected $fillable = ['event_id', 'name', 'shape', 'capacity', 'pos_x', 'pos_y', 'sort_order'];

    protected $attributes = ['shape' => 'round', 'capacity' => 8, 'pos_x' => 0, 'pos_y' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'pos_x' => 'integer', 'pos_y' => 'integer', 'sort_order' => 'integer'];
    }

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
