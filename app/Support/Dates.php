<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Render a stored timestamp in the timezone the people reading it live in.
 *
 * Timestamps are stored in UTC, which is right — it is the only way a single
 * instant means the same thing everywhere. But they were then formatted
 * straight off that UTC value, so an organizer who applied at 11:11 in the
 * morning saw "3:11 AM" on their own application, and anything that happened
 * between midnight and 8am Malaysian time showed the PREVIOUS day's date.
 *
 * Storage stays UTC. Only display moves.
 */
final class Dates
{
    /** The timezone the interface renders in. */
    public static function tz(): string
    {
        return (string) config('app.display_timezone', 'Asia/Kuala_Lumpur');
    }

    /**
     * Format an instant for a person to read, in the display timezone.
     *
     * Null-safe, because most of these come off nullable columns and the call
     * sites were previously `optional($x)->format(...)`.
     */
    public static function display(?DateTimeInterface $at, string $format = 'j M Y'): ?string
    {
        return $at === null ? null : Carbon::instance($at)->setTimezone(self::tz())->format($format);
    }

    /** The same instant as a Carbon in the display timezone, for further work. */
    public static function local(?DateTimeInterface $at): ?Carbon
    {
        return $at === null ? null : Carbon::instance($at)->setTimezone(self::tz());
    }
}
