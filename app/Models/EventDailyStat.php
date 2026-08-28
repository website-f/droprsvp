<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventDailyStat extends Model
{
    protected $fillable = ['event_id', 'stat_date', 'impressions', 'clicks'];

    protected function casts(): array
    {
        return ['stat_date' => 'date'];
    }

    /** Record one impression or click for an event today (race-safe). */
    public static function bump(int $eventId, string $metric): void
    {
        if (! in_array($metric, ['impressions', 'clicks'], true)) {
            return;
        }

        $row = static::firstOrCreate(['event_id' => $eventId, 'stat_date' => today()]);
        $row->increment($metric);
    }
}
