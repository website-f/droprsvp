<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person moving through one sequence, for one reason (an event, an order, a subscription). */
class EdmEnrollment extends Model
{
    protected $attributes = ['status' => 'active', 'next_step' => 0];

    protected $fillable = ['automation_id', 'email', 'name', 'user_id', 'context_key', 'context', 'anchor_at', 'status', 'next_step', 'next_at', 'exit_reason', 'completed_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'anchor_at' => 'datetime', 'next_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(EdmAutomation::class, 'automation_id');
    }
}
