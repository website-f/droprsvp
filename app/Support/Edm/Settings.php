<?php

namespace App\Support\Edm;

use App\Models\Setting;

/**
 * EDM settings an admin can change without a deploy, layered over
 * config/edm.php (which reads .env). A blank admin value means "use the
 * config default", so clearing a field restores it rather than emptying it.
 */
final class Settings
{
    public static function all(): array
    {
        $saved = Setting::getArray('edm', []);

        return [
            'hourly_limit' => (int) ($saved['hourly_limit'] ?? 0) > 0
                ? (int) $saved['hourly_limit']
                : (int) config('edm.hourly_limit', 100),
            'from_name' => self::pick($saved['from_name'] ?? null, (string) config('edm.from.name', 'DropRSVP')),
            'reply_to' => self::pick($saved['reply_to'] ?? null, (string) config('edm.reply_to', '')),
            'postal_address' => self::pick($saved['postal_address'] ?? null, (string) config('edm.postal_address', '')),
        ];
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function save(array $values): void
    {
        $current = Setting::getArray('edm', []);

        foreach (['hourly_limit', 'from_name', 'reply_to', 'postal_address'] as $key) {
            if (array_key_exists($key, $values)) {
                $current[$key] = $values[$key];
            }
        }

        Setting::putArray('edm', $current);
    }

    private static function pick(mixed $saved, string $fallback): string
    {
        $saved = is_string($saved) ? trim($saved) : '';

        return $saved !== '' ? $saved : $fallback;
    }
}
