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

    /** @return array<int, array{name: string, slug: string}> */
    public static function all(): array
    {
        return array_map(fn (string $name) => ['name' => $name, 'slug' => Str::slug($name)], self::LIST);
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
