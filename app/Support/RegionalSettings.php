<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * JSON settings a region can override: homepage SEO, blog SEO, the event and
 * organizer SEO templates.
 *
 * The plain key ("home_seo") is the default for every region. A region's own
 * key ("home_seo:en-sg") holds only what that country changed; anything it
 * leaves blank falls through to the default. One query reads both.
 */
final class RegionalSettings
{
    public static function key(string $key, string $region): string
    {
        return $key.':'.$region;
    }

    /**
     * The default merged with the region's non-blank overrides.
     *
     * @param  string|null  $region  null = the region of this request; '' = the default alone
     */
    public static function get(string $key, ?string $region = null): array
    {
        $region ??= Regions::current()['code'] ?? '';
        $own = $region !== '' ? self::key($key, $region) : null;

        $rows = Setting::query()->whereIn('key', array_filter([$key, $own]))->pluck('value', 'key');

        return array_replace(
            self::decode($rows[$key] ?? null),
            $own ? self::filled(self::decode($rows[$own] ?? null)) : [],
        );
    }

    /** Only what a region changed itself (for its tab in the admin). */
    public static function own(string $key, string $region): array
    {
        return self::filled(Setting::getArray(self::key($key, $region)));
    }

    /** Save the default (region null) or a region's overrides (blanks dropped). */
    public static function put(string $key, ?string $region, array $data): void
    {
        if ($region === null) {
            Setting::putArray($key, $data);

            return;
        }

        $data = self::filled($data);
        if ($data === []) {
            Setting::query()->whereKey(self::key($key, $region))->delete();

            return;
        }

        Setting::putArray(self::key($key, $region), $data);
    }

    /** The region an admin screen is editing, from ?region= (null = the default). */
    public static function editing(Request $request): ?string
    {
        $code = (string) ($request->input('region') ?? $request->query('region') ?? '');

        return in_array($code, Regions::codes(), true) ? $code : null;
    }

    /**
     * Tabs for the admin screen: Default, then every enabled region, marking
     * the ones that have their own settings.
     *
     * @return list<array{code: string|null, name: string, flag: string, customised: bool, active: bool}>
     */
    public static function tabs(string $key, ?string $active): array
    {
        $regions = Regions::enabled();
        if (count($regions) < 2) {
            return [];
        }

        $customised = Setting::query()->where('key', 'like', $key.':%')->pluck('key')
            ->map(fn ($k) => substr($k, strlen($key) + 1))->all();

        $tabs = [['code' => null, 'name' => 'All regions (default)', 'flag' => '🌐', 'customised' => false, 'active' => $active === null]];
        foreach ($regions as $r) {
            $tabs[] = [
                'code' => $r['code'], 'name' => $r['name'], 'flag' => $r['flag'],
                'customised' => in_array($r['code'], $customised, true), 'active' => $active === $r['code'],
            ];
        }

        return $tabs;
    }

    private static function decode(?string $raw): array
    {
        $value = $raw ? json_decode($raw, true) : null;

        return is_array($value) ? $value : [];
    }

    private static function filled(array $data): array
    {
        return array_filter($data, fn ($v) => is_string($v) ? trim($v) !== '' : filled($v));
    }
}
