<?php

namespace App\Support\Chat;

use App\Models\Chat\IpBan;
use Illuminate\Support\Facades\Cache;

/**
 * IP bans for chat: by hand from the moderation screen, or automatically for a
 * client that keeps tripping the rate limits (a broken script, a scraper, an
 * attack). Checked on every chat request, so it must be cheap: the active bans
 * are held in the cache as one set, reloaded from the database when missing.
 */
final class IpGuard
{
    private const SET = 'chat:ipbans';

    public static function isBanned(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }

        $bans = Cache::get(self::SET);

        if (! is_array($bans)) {
            $bans = self::reload();
        }

        $until = $bans[$ip] ?? null;

        // 0 = permanent; otherwise a unix time.
        return $until !== null && ($until === 0 || $until > now()->getTimestamp());
    }

    /**
     * A rate-limit violation from this IP. After ban_strikes in ten minutes,
     * ban it for ban_minutes. Returns true if this strike caused a ban.
     */
    public static function strike(string $ip): bool
    {
        $key = 'chat:strikes:'.$ip;
        Cache::add($key, 0, 600);
        $strikes = (int) Cache::increment($key);

        if ($strikes < (int) ChatSettings::get('ban_strikes')) {
            return false;
        }

        Cache::forget($key);
        self::ban($ip, 'Automatic: kept exceeding the chat rate limits', now()->addMinutes((int) ChatSettings::get('ban_minutes')), automatic: true);

        return true;
    }

    public static function ban(string $ip, ?string $reason, ?\DateTimeInterface $until, bool $automatic = false, ?int $by = null): IpBan
    {
        $ban = IpBan::updateOrCreate(['ip' => $ip], [
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'until' => $until,
            'automatic' => $automatic,
            'created_by' => $by,
        ]);

        self::reload();

        return $ban;
    }

    public static function unban(string $ip): void
    {
        IpBan::where('ip', $ip)->delete();
        self::reload();
    }

    /** @return array<string, int> ip => until (unix) or 0 for permanent */
    public static function reload(): array
    {
        $bans = IpBan::query()
            ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))
            ->get(['ip', 'until'])
            ->mapWithKeys(fn (IpBan $b) => [$b->ip => $b->until ? $b->until->getTimestamp() : 0])
            ->all();

        Cache::forever(self::SET, $bans);

        return $bans;
    }
}
