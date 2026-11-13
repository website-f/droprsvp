<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ticket checked in on one day (event time). See CheckInService. */
class CheckIn extends Model
{
    protected $fillable = ['event_id', 'ticket_id', 'day', 'checked_in_at', 'checked_in_by', 'via'];

    protected function casts(): array
    {
        // `day` stays a plain Y-m-d string (the event's local date), so it
        // compares the same on every database.
        return ['checked_in_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
