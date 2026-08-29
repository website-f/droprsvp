<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Canonical list of cities used for SEO-friendly discovery URLs
 * (/en-my/{city}/{category}). A controlled vocabulary keeps the URL space
 * clean and avoids thin, duplicate location pages.
 */
class Cities
{
    /** The special "any city" URL segment used for country-wide category pages. */
    public const ANY = 'all';

    /** @var string[] Display names (slugs are derived with Str::slug). */
    public const LIST = [
        'Kuala Lumpur',
        'Petaling Jaya',
        'Shah Alam',
        'Subang Jaya',
        'Klang',
        'Putrajaya',
        'Cyberjaya',
        'George Town',
        'Butterworth',
        'Johor Bahru',
        'Ipoh',
        'Melaka',
        'Seremban',
        'Kuantan',
        'Kota Kinabalu',
        'Kuching',
        'Alor Setar',
        'Kota Bharu',
        'Kuala Terengganu',
        'Langkawi',
    ];

    /** Approx city-centre coordinates [lat, lng] — used to show "~N km away". */
    public const COORDS = [
        'Kuala Lumpur' => [3.1390, 101.6869],
        'Petaling Jaya' => [3.1073, 101.6067],
        'Shah Alam' => [3.0733, 101.5185],
        'Subang Jaya' => [3.0567, 101.5851],
        'Klang' => [3.0449, 101.4455],
        'Putrajaya' => [2.9264, 101.6964],
        'Cyberjaya' => [2.9188, 101.6520],
        'George Town' => [5.4141, 100.3288],
        'Butterworth' => [5.3991, 100.3638],
        'Johor Bahru' => [1.4927, 103.7414],
        'Ipoh' => [4.5975, 101.0901],
        'Melaka' => [2.1896, 102.2501],
        'Seremban' => [2.7297, 101.9381],
        'Kuantan' => [3.8077, 103.3260],
        'Kota Kinabalu' => [5.9804, 116.0735],
        'Kuching' => [1.5535, 110.3593],
        'Alor Setar' => [6.1264, 100.3673],
        'Kota Bharu' => [6.1254, 102.2381],
        'Kuala Terengganu' => [5.3302, 103.1408],
        'Langkawi' => [6.3500, 99.8000],
    ];

    /** @return array<int, array{name: string, slug: string}> */
    public static function all(): array
    {
        return array_map(fn (string $name) => ['name' => $name, 'slug' => Str::slug($name)], self::LIST);
    }

    /** @return array{lat: float, lng: float}|null */
    public static function coordsForName(?string $name): ?array
    {
        if ($name && isset(self::COORDS[$name])) {
            return ['lat' => self::COORDS[$name][0], 'lng' => self::COORDS[$name][1]];
        }

        return null;
    }

    /** Resolve a URL slug back to its canonical display name (null if unknown / "all"). */
    public static function nameForSlug(?string $slug): ?string
    {
        if (! $slug || $slug === self::ANY) {
            return null;
        }

        foreach (self::LIST as $name) {
            if (Str::slug($name) === $slug) {
                return $name;
            }
        }

        return null;
    }

    public static function slugForName(?string $name): ?string
    {
        return $name ? Str::slug($name) : null;
    }

    public static function isKnownSlug(string $slug): bool
    {
        return $slug === self::ANY || self::nameForSlug($slug) !== null;
    }
}
