<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Ticket extends Model
{
    protected $fillable = [
        'order_id', 'ticket_type_id', 'seat_section_id', 'event_id', 'seating_table_id', 'qr_token', 'attendee_name',
        'attendee_email', 'status', 'seat_label', 'checked_in_at', 'checked_in_by', 'check_in_log', 'custom_answers',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'check_in_log' => 'array',
            'custom_answers' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'qr_token';
    }

    protected static function booted(): void
    {
        // Every ticket gets a unique, unguessable QR token.
        static::creating(function (Ticket $ticket) {
            if (empty($ticket->qr_token)) {
                $ticket->qr_token = (string) Str::ulid();
            }
        });
    }

    /**
     * Tickets that still stand for someone turning up — everything except the
     * voided, cancelled and refunded ones. This is what "sold" and "going"
     * mean everywhere in the app, so counts tally wherever they are shown.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', ['valid', 'checked_in']);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function seatingTable(): BelongsTo
    {
        return $this->belongsTo(SeatingTable::class, 'seating_table_id');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
