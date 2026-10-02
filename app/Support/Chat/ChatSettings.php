<?php

namespace App\Support\Chat;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Chat settings an admin can tune in Admin → Chat → Settings, without a deploy.
 * A blank or invalid value falls back to the default, so a typo can never set
 * the poll interval to zero and flood the server.
 */
final class ChatSettings
{
    /** key => [default, min, max] */
    public const SPEC = [
        // Polling, in seconds. The server picks one of these per response and
        // the browser obeys it; hidden tabs slow down further on their own.
        'poll_active' => [2, 1, 30],         // a conversation with activity in the last 2 minutes
        'poll_open' => [5, 2, 60],           // a conversation open, quiet
        'poll_inbox' => [10, 3, 120],        // the inbox, nothing open
        'poll_badge' => [45, 15, 600],       // any other page: just the unread badge
        'poll_hidden' => [60, 15, 900],      // tab in the background
        // Load shedding: above this many polls a second across the site, every
        // interval is stretched (up to 4x) until the load drops.
        'max_polls_per_second' => [25, 2, 1000],

        // Anti-spam.
        'messages_per_minute' => [20, 1, 120],
        'new_conversations_per_day' => [20, 1, 500],
        'new_account_conversations_per_day' => [3, 0, 100],
        'request_message_limit' => [3, 1, 20],
        'duplicate_limit' => [3, 2, 50],     // same text to this many people in 10 minutes = spam

        // Images.
        'max_image_mb' => [5, 1, 20],

        // Abuse: a client tripping the rate limit this many times in ten
        // minutes is banned for ban_minutes.
        'poll_limit_per_minute' => [90, 20, 600],
        'ban_strikes' => [5, 2, 100],
        'ban_minutes' => [30, 1, 10080],

        // Email about unread messages.
        'notify_after_minutes' => [10, 1, 1440],
        'notify_cooldown_hours' => [6, 1, 168],
    ];

    /**
     * Cached: every poll reads these, and a poll that finds nothing new must
     * not touch the database at all. Cleared on save.
     *
     * @return array<string, int|bool>
     */
    public static function all(): array
    {
        return Cache::rememberForever('chat:settings', fn () => self::resolve());
    }

    /** @return array<string, int|bool> */
    private static function resolve(): array
    {
        $saved = Setting::getArray('chat', []);
        $out = [];

        foreach (self::SPEC as $key => [$default, $min, $max]) {
            $value = $saved[$key] ?? null;
            $out[$key] = is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
        }

        $out['images_enabled'] = (bool) ($saved['images_enabled'] ?? true);
        $out['enabled'] = (bool) ($saved['enabled'] ?? true);

        return $out;
    }

    public static function get(string $key): int|bool
    {
        return self::all()[$key];
    }

    public static function save(array $values): void
    {
        $current = Setting::getArray('chat', []);

        foreach (array_keys(self::SPEC) as $key) {
            if (array_key_exists($key, $values)) {
                $current[$key] = (int) $values[$key];
            }
        }

        foreach (['images_enabled', 'enabled'] as $flag) {
            if (array_key_exists($flag, $values)) {
                $current[$flag] = (bool) $values[$flag];
            }
        }

        Setting::putArray('chat', $current);
        Cache::forget('chat:settings');
    }
}
