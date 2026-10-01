<?php

namespace App\Support\Edm;

use App\Models\EmailSend;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * How many campaign emails may go out right now.
 *
 * Three limits, and the tightest wins:
 *
 *  1. The hourly limit, over a rolling hour. The host caps outgoing mail per
 *     domain per hour and that cap is shared with tickets and receipts, so
 *     campaigns are held to a fraction of it (see config/edm.php).
 *
 *  2. An even spread: at most limit/60 per minute. Two hundred messages in the
 *     first minute of an hour looks like exactly what spam looks like, and some
 *     hosts flag a burst even when the hourly total is fine.
 *
 *  3. Warm-up. A new sending address has no reputation, and going from zero to
 *     thousands a day is the classic way to land in spam for months. The daily
 *     allowance starts low and doubles on a schedule — counted from the first
 *     campaign email ever sent, so it begins on its own with nothing to switch
 *     on, and is visible on the settings page.
 */
final class Throttle
{
    public static function hourlyLimit(): int
    {
        $saved = (int) (Setting::getArray('edm', [])['hourly_limit'] ?? 0);

        return max(1, $saved > 0 ? $saved : (int) config('edm.hourly_limit', 100));
    }

    public static function perMinute(): int
    {
        return max(1, (int) ceil(self::hourlyLimit() / 60));
    }

    /** The warm-up allowance for a rolling 24 hours, or null once it no longer binds. */
    public static function warmupDailyCap(): ?int
    {
        if (! config('edm.warmup.enabled', true)) {
            return null;
        }

        $start = max(1, (int) config('edm.warmup.start_per_day', 100));
        $every = max(1, (int) config('edm.warmup.double_every_days', 3));

        $first = EmailSend::whereNotNull('sent_at')->min('sent_at');

        if (! $first) {
            return $start;
        }

        $days = (int) floor(now()->diffInDays(Carbon::parse($first), true));
        $cap = $start * (2 ** intdiv($days, $every));

        // Once the warm-up allowance exceeds a full day at the hourly limit, it
        // has nothing left to do.
        return $cap >= self::hourlyLimit() * 24 ? null : $cap;
    }

    public static function sentInLastHour(): int
    {
        return EmailSend::where('sent_at', '>=', now()->subHour())->count();
    }

    public static function sentInLastDay(): int
    {
        return EmailSend::where('sent_at', '>=', now()->subDay())->count();
    }

    /** How many may be sent in this run. */
    public static function budget(): int
    {
        $budget = min(self::perMinute(), self::hourlyLimit() - self::sentInLastHour());

        if (($cap = self::warmupDailyCap()) !== null) {
            $budget = min($budget, $cap - self::sentInLastDay());
        }

        return max(0, $budget);
    }

    /** For the admin: what is binding right now, in numbers. */
    public static function status(): array
    {
        return [
            'hourly_limit' => self::hourlyLimit(),
            'per_minute' => self::perMinute(),
            'sent_last_hour' => self::sentInLastHour(),
            'sent_last_day' => self::sentInLastDay(),
            'warmup_daily_cap' => self::warmupDailyCap(),
            'budget_now' => self::budget(),
        ];
    }
}
