<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'ticket_type_id', 'seat_section_id', 'seat_id', 'seat_label', 'seating_table_id', 'name', 'unit_price', 'quantity', 'line_total'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The table the buyer picked for this line, when the event lets them. */
    public function seatingTable(): BelongsTo
    {
        return $this->belongsTo(SeatingTable::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }
}
