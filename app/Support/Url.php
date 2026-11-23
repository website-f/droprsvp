<?php

namespace App\Support;

/**
 * Canonical public URLs.
 *
 * Every indexable page lives under the locale prefix and ends with a trailing
 * slash — https://www.droprsvp.com/en-my/blog/ — which is also the form Apache
 * redirects to (see the "Canonical path" rule in public/.htaccess). Building
 * them in one place keeps the <link rel=canonical>, og:url, JSON-LD, the
 * sitemap and in-app links on exactly the same string: a mismatch between any
 * two of those is what splits one page's ranking across two URLs.
 *
 * App screens (auth, checkout, /my, host, admin) are noindexed and deliberately
 * stay off this scheme — a locale prefix buys them nothing and moving them
 * would break OAuth callbacks and payment return URLs.
 */
class Url
{
    /**
     * The original region, and still the default one out of the box. The
     * region of a URL is whichever is being served (Regions::current) — see
     * path(); this constant is only the fallback.
     */
    public const LOCALE = 'en-my';

    /** Relative canonical path in the current region — Url::path('blog', $slug) → "/en-my/blog/my-post/". */
    public static function path(string ...$segments): string
    {
        return self::pathIn(Regions::current()['code'], ...$segments);
    }

    /** The same, in a given region: Url::pathIn('en-sg', 'e', $slug). */
    public static function pathIn(string $region, string ...$segments): string
    {
        $parts = array_filter(
            array_map(fn (string $s) => trim($s, '/'), $segments),
            fn (string $s) => $s !== '',
        );

        return '/'.implode('/', [$region ?: self::LOCALE, ...$parts]).'/';
    }

    /** Absolute URL in a given region — an event's own page, whatever region is being browsed. */
    public static function in(string $region, string ...$segments): string
    {
        return rtrim(url('/'), '/').self::pathIn($region, ...$segments);
    }

    /**
     * Absolute URL in the DEFAULT region, for content that is not about a
     * place (blog, help, CMS pages): reachable under every region, but one
     * canonical home so search engines see one page, not one per region.
     */
    public static function global(string ...$segments): string
    {
        return self::in(Regions::default()['code'], ...$segments);
    }

    /**
     * Absolute canonical URL for a public page. Built by hand rather than with
     * url(), which trims the trailing slash straight back off again.
     */
    public static function to(string ...$segments): string
    {
        return rtrim(url('/'), '/').self::path(...$segments);
    }

    /**
     * Add the canonical trailing slash to an already-built URL. Query strings are
     * preserved, and anything whose last segment looks like a file (sitemap.xml,
     * calendar.ics, an uploaded image) is left exactly as it is.
     */
    public static function slash(string $url): string
    {
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        $last = substr($path, (int) strrpos($path, '/') + 1);

        if (! str_ends_with($path, '/') && ! str_contains($last, '.')) {
            $path .= '/';
        }

        return $path.($query === null ? '' : '?'.$query);
    }
}
