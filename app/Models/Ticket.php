<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Ticket extends Model
{
    protected $fillable = [
        'order_id', 'ticket_type_id', 'event_id', 'qr_token', 'attendee_name', 'attendee_email',
        'status', 'seat_label', 'checked_in_at', 'checked_in_by',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
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

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
