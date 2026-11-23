<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The regions the site runs in — en-my, en-sg, en-id… — each the first
 * segment of every public URL and the place an event belongs to.
 *
 * Which regions are on, and which one is the default, is the superadmin's
 * choice (Admin → Settings → Regions). Out of the box that is Southeast
 * Asia, with Malaysia as the default; every other country in RegionCatalog
 * can be added. The region in the URL decides what the public pages list:
 * an /en-sg/ visitor sees Singapore's events.
 *
 * Content that is not about a place — the blog, help, contact, CMS pages —
 * is reachable under every region but belongs to the default one: that is
 * where its canonical URL points (Url::global).
 */
final class Regions
{
    /** Turned on when the setting has never been saved. */
    public const DEFAULT_ENABLED = ['MY', 'SG', 'ID', 'TH', 'PH', 'VN', 'BN', 'KH', 'LA', 'MM', 'TL'];

    public const DEFAULT_REGION = 'MY';

    /** The shape of a code in a URL: en-my. */
    public const PATTERN = '[a-z]{2}-[a-z]{2}';

    private const CACHE = 'site.regions';

    /** The region of the request being served (set by the SetRegion middleware). */
    private static ?array $current = null;

    /**
     * The regions that are on, default first, then by name.
     *
     * @return list<array{code: string, country: string, name: string, currency: string, timezone: string, area: string, flag: string, default: bool}>
     */
    public static function enabled(): array
    {
        try {
            return Cache::rememberForever(self::CACHE, fn () => self::build(Setting::getArray('regions', [])));
        } catch (\Throwable) {
            // No settings (or cache) table yet — a fresh install mid-migration,
            // a unit test without a database: the defaults, uncached.
            return self::build([]);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function build(array $saved): array
    {
        $countries = array_values(array_filter(
            array_map('strtoupper', (array) ($saved['enabled'] ?? self::DEFAULT_ENABLED)),
            fn ($c) => isset(RegionCatalog::COUNTRIES[$c]),
        ));
        $default = strtoupper((string) ($saved['default'] ?? self::DEFAULT_REGION));

        if (! in_array($default, $countries, true)) {
            $default = $countries[0] ?? self::DEFAULT_REGION;
            $countries = array_values(array_unique([$default, ...$countries]));
        }

        $rows = array_map(fn (string $c) => self::describe($c, $c === $default), $countries);
        usort($rows, fn ($a, $b) => [$b['default'], $a['name']] <=> [$a['default'], $b['name']]);

        return $rows;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** @return array{code: string, country: string, name: string, currency: string, timezone: string, area: string, flag: string, default: bool} */
    public static function describe(string $country, bool $default = false): array
    {
        $country = strtoupper($country);
        [$name, $currency, $timezone, $area] = RegionCatalog::COUNTRIES[$country] ?? ['Malaysia', 'MYR', 'Asia/Kuala_Lumpur', 'Southeast Asia'];

        return [
            'code' => 'en-'.strtolower($country),
            'country' => $country,
            'name' => $name,
            'currency' => $currency,
            'timezone' => $timezone,
            'area' => $area,
            'flag' => self::flag($country),
            'default' => $default,
        ];
    }

    /** The default region (where content that is not about a place lives). */
    public static function default(): array
    {
        foreach (self::enabled() as $r) {
            if ($r['default']) {
                return $r;
            }
        }

        return self::describe(self::DEFAULT_REGION, true);
    }

    /** One enabled region by its code (en-sg), or null. */
    public static function find(?string $code): ?array
    {
        foreach (self::enabled() as $r) {
            if ($r['code'] === strtolower((string) $code)) {
                return $r;
            }
        }

        return null;
    }

    public static function isEnabled(?string $code): bool
    {
        return self::find($code) !== null;
    }

    /** The region of this request — the default outside a region URL. */
    public static function current(): array
    {
        return self::$current ?? self::default();
    }

    public static function setCurrent(?array $region): void
    {
        self::$current = $region;
    }

    /** Whether this request is being served in a region (a region URL, or set explicitly). */
    public static function hasCurrent(): bool
    {
        return self::$current !== null;
    }

    /** Codes only, for validation. @return list<string> */
    public static function codes(): array
    {
        return array_column(self::enabled(), 'code');
    }

    /**
     * Where to send someone who arrives at "/": the region they last browsed
     * (a cookie), else their browser's country (Accept-Language "en-SG"), else
     * the default.
     */
    public static function preferred(Request $request): array
    {
        if ($r = self::find($request->cookie('region'))) {
            return $r;
        }

        foreach ($request->getLanguages() as $lang) {
            $parts = preg_split('/[-_]/', $lang);

            if (isset($parts[1]) && strlen($parts[1]) === 2 && ($r = self::find('en-'.strtolower($parts[1])))) {
                return $r;
            }
        }

        return self::default();
    }

    /** The region whose timezone this is, if any is on (used to suggest one). */
    public static function forTimezone(?string $tz): ?array
    {
        foreach (self::enabled() as $r) {
            if ($r['timezone'] === $tz) {
                return $r;
            }
        }

        foreach (self::enabled() as $r) {
            if ($tz && in_array($tz, \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $r['country']), true)) {
                return $r;
            }
        }

        return null;
    }

    /** "🇸🇬" from "SG". */
    public static function flag(string $country): string
    {
        $country = strtoupper($country);

        return mb_chr(0x1F1E6 + ord($country[0]) - 65).mb_chr(0x1F1E6 + ord($country[1]) - 65);
    }
}
