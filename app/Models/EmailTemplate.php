<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reusable email design: the starting point for campaigns, never sent itself. */
class EmailTemplate extends Model
{
    protected $fillable = ['organizer_id', 'name', 'description', 'subject', 'preheader', 'design', 'created_by'];

    protected function casts(): array
    {
        return ['design' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
