<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email in a sequence, sent at an offset from the trigger moment. Its
 * content, tracking and statistics live on its own hidden EmailCampaign.
 */
class EdmAutomationStep extends Model
{
    public const UNITS = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

    protected $fillable = ['automation_id', 'campaign_id', 'position', 'delay_value', 'delay_unit', 'delay_direction', 'conditions', 'skipped_count'];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'delay_value' => 'integer'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(EdmAutomation::class, 'automation_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    /** Signed offset from the trigger moment, in minutes (before = negative). */
    public function offsetMinutes(): int
    {
        $minutes = max(0, $this->delay_value) * (self::UNITS[$this->delay_unit] ?? 1440);

        return $this->delay_direction === 'before' ? -$minutes : $minutes;
    }

    /** "3 days before", "1 hour after", "immediately". */
    public function timingLabel(): string
    {
        if ($this->delay_value <= 0) {
            return 'Immediately';
        }

        $unit = rtrim($this->delay_unit, 's');

        return $this->delay_value.' '.($this->delay_value === 1 ? $unit : $unit.'s').' '.$this->delay_direction;
    }
}
