<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sequence of emails that sends itself. See App\Services\Edm\Automations. */
class EdmAutomation extends Model
{
    public const TRIGGERS = ['event_reminder', 'post_event', 'abandoned_checkout', 'welcome'];

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['organizer_id', 'trigger', 'name', 'status', 'settings', 'activated_at', 'created_by'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'activated_at' => 'datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(EdmAutomationStep::class, 'automation_id')->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(EdmEnrollment::class, 'automation_id');
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
