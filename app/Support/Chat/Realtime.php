<?php

namespace App\Support\Chat;

use Illuminate\Support\Facades\Cache;

/**
 * What makes polling cheap: everything a poll needs to answer "anything new?"
 * lives in the cache, never the database.
 *
 *  version   a per-user stamp, set to the current time in milliseconds
 *            whenever something they can see changes (a message to them, a
 *            read receipt for them, a request accepted). A poll compares the
 *            browser's stamp with this one; equal means nothing changed and
 *            the answer is a few bytes, with no query at all.
 *  bversion  a stamp that changes whenever an announcement is sent or removed.
 *  typing    "who is typing in this conversation", for six seconds.
 *  seen      when a user last polled, for "online" and "last seen".
 *  load      polls per second across the site, sampled, for load shedding.
 *
 * Losing the cache (a deploy, a clear) is harmless: every browser's stamp then
 * differs from the (missing) server one, so each fetches a full refresh once.
 */
final class Realtime
{
    /** Mark that something changed for these users. */
    public static function bump(int ...$userIds): void
    {
        $now = (int) floor(microtime(true) * 1000);

        foreach (array_unique($userIds) as $id) {
            Cache::put("chat:v:{$id}", $now, now()->addDays(2));
        }
    }

    public static function version(int $userId): int
    {
        return (int) Cache::get("chat:v:{$userId}", 0);
    }

    public static function broadcastVersion(): int
    {
        return (int) Cache::get('chat:bv', 0);
    }

    public static function setBroadcastVersion(int $id): void
    {
        Cache::forever('chat:bv', $id);
    }

    public static function typing(int $conversationId, int $userId): void
    {
        Cache::put("chat:typing:{$conversationId}", $userId, now()->addSeconds(6));
    }

    /** Is someone OTHER than $userId typing in this conversation? */
    public static function isOtherTyping(int $conversationId, int $userId): bool
    {
        $typer = Cache::get("chat:typing:{$conversationId}");

        return $typer !== null && (int) $typer !== $userId;
    }

    public static function stopTyping(int $conversationId): void
    {
        Cache::forget("chat:typing:{$conversationId}");
    }

    /** Record a poll as presence — written at most once a minute per user. */
    public static function seen(int $userId): void
    {
        $key = "chat:seen:{$userId}";
        $last = (int) Cache::get($key, 0);

        if (now()->getTimestamp() - $last >= 60) {
            Cache::put($key, now()->getTimestamp(), now()->addDays(7));
        }
    }

    /** Unix time of a user's last poll, or null. */
    public static function lastSeen(int $userId): ?int
    {
        $t = Cache::get("chat:seen:{$userId}");

        return $t ? (int) $t : null;
    }

    public static function isOnline(int $userId): bool
    {
        $t = self::lastSeen($userId);

        return $t !== null && now()->getTimestamp() - $t <= 90;
    }

    /**
     * Count this poll toward the site-wide rate, sampled 1 in 10 so counting
     * does not itself become the load. Returns the stretch factor (1–4) every
     * interval should be multiplied by right now.
     */
    public static function loadFactor(int $maxPerSecond): float
    {
        $bucket = 'chat:load:'.intdiv(now()->getTimestamp(), 10);

        if (random_int(1, 10) === 1) {
            Cache::add($bucket, 0, 30);
            Cache::increment($bucket);
        }

        // Sampled count x10 polls, over a 10-second bucket.
        $perSecond = (int) Cache::get($bucket, 0);

        return $perSecond > $maxPerSecond ? min(4.0, $perSecond / max(1, $maxPerSecond)) : 1.0;
    }

    /** The current estimated polls per second, for the admin screen. */
    public static function currentLoad(): int
    {
        return (int) Cache::get('chat:load:'.intdiv(now()->getTimestamp(), 10), 0)
            ?: (int) Cache::get('chat:load:'.(intdiv(now()->getTimestamp(), 10) - 1), 0);
    }
}
