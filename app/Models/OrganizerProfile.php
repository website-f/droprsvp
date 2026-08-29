<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerProfile extends Model
{
    protected $fillable = [
        'user_id', 'event_types', 'revenue_band', 'events_per_year',
        'audience_size', 'age_range', 'completed_at',
        'status', 'business_name', 'website', 'phone', 'bio', 'poster', 'gallery',
        'review_reason', 'submitted_at', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'gallery' => 'array',
            'completed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
