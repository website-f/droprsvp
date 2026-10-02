<?php

namespace App\Support;

/**
 * An organizer's social profiles.
 *
 * Stored as { platform: url } with only the platforms they filled in. The
 * platform keys match resources/js/components/social-icons.tsx, which draws
 * the logos.
 *
 * People type these every way imaginable, so normalise() accepts:
 *   - a full link                  https://www.instagram.com/boardlah
 *   - a link without the scheme    instagram.com/boardlah
 *   - a handle                     @boardlah  (where the platform has one)
 *   - for WhatsApp, a phone number +60 12-345 6789
 * and rejects a link that is plainly another platform's — a TikTok URL in the
 * Instagram box would otherwise put an Instagram logo on a TikTok page.
 */
final class SocialLinks
{
    /**
     * platform => [label, hosts the link may be on, handle URL template or null].
     * Order is the order logos are shown in.
     */
    public const PLATFORMS = [
        'instagram' => ['Instagram', ['instagram.com', 'instagr.am'], 'https://www.instagram.com/{h}'],
        'facebook' => ['Facebook', ['facebook.com', 'fb.com', 'fb.me'], 'https://www.facebook.com/{h}'],
        'tiktok' => ['TikTok', ['tiktok.com'], 'https://www.tiktok.com/@{h}'],
        'threads' => ['Threads', ['threads.net', 'threads.com'], 'https://www.threads.net/@{h}'],
        'x' => ['X (Twitter)', ['x.com', 'twitter.com'], 'https://x.com/{h}'],
        'youtube' => ['YouTube', ['youtube.com', 'youtu.be'], 'https://www.youtube.com/@{h}'],
        'linkedin' => ['LinkedIn', ['linkedin.com', 'lnkd.in'], null],
        'whatsapp' => ['WhatsApp', ['wa.me', 'whatsapp.com'], null],
        'telegram' => ['Telegram', ['t.me', 'telegram.me', 'telegram.org'], 'https://t.me/{h}'],
        'pinterest' => ['Pinterest', ['pinterest.com', 'pin.it'], 'https://www.pinterest.com/{h}'],
    ];

    public static function label(string $platform): string
    {
        return self::PLATFORMS[$platform][0] ?? $platform;
    }

    /**
     * Turn one typed value into a link for that platform.
     *
     * @return string|null|false the URL; null when blank (nothing to save);
     *                           false when it cannot be a link for this platform
     */
    public static function normalise(string $platform, mixed $value): string|null|false
    {
        if (! isset(self::PLATFORMS[$platform])) {
            return false;
        }

        // An emptied box arrives as null (Laravel converts '' to null).
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        [, $hosts, $handleTemplate] = self::PLATFORMS[$platform];

        // A phone number for WhatsApp becomes a wa.me link.
        if ($platform === 'whatsapp' && preg_match('/^\+?[\d\s\-().]{7,20}$/', $value)) {
            $digits = preg_replace('/\D/', '', $value);

            // A local Malaysian number (012…) gets its country code.
            if (str_starts_with($digits, '0')) {
                $digits = '6'.$digits;
            }

            return 'https://wa.me/'.$digits;
        }

        // A bare handle: "@boardlah" or "boardlah" (no dot, no slash).
        if ($handleTemplate && preg_match('/^@?([A-Za-z0-9._-]{1,60})$/', $value, $m) && ! str_contains($m[1], '.')) {
            return str_replace('{h}', $m[1], $handleTemplate);
        }

        $url = WebsiteUrl::normalise($value);

        if (! $url) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach ($hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return $url;
            }
        }

        // Pinterest runs country domains (pinterest.co.uk, pinterest.com.au).
        if ($platform === 'pinterest' && preg_match('/(^|\.)pinterest\.[a-z.]+$/', $host)) {
            return $url;
        }

        return false;
    }

    /**
     * Clean a whole submitted set.
     *
     * @param  array<string,mixed>  $input
     * @return array{0: array<string,string>, 1: array<string,string>} [links, errors keyed by platform]
     */
    public static function clean(array $input): array
    {
        $links = [];
        $errors = [];

        foreach (array_keys(self::PLATFORMS) as $platform) {
            if (! array_key_exists($platform, $input)) {
                continue;
            }

            $url = self::normalise($platform, $input[$platform]);

            if ($url === false) {
                $errors[$platform] = 'That does not look like a '.self::label($platform).' link.';
            } elseif ($url !== null) {
                $links[$platform] = mb_substr($url, 0, 2048);
            }
        }

        return [$links, $errors];
    }

    /**
     * Stored links in display order, for the public page. Anything that would
     * not pass normalise() today (an older value, a hand-edited row) is left
     * out rather than rendered as a broken or unsafe link.
     *
     * @return array<int, array{platform:string,label:string,url:string}>
     */
    public static function forDisplay(?array $stored): array
    {
        $out = [];

        foreach (array_keys(self::PLATFORMS) as $platform) {
            $url = $stored[$platform] ?? null;

            if (is_string($url) && ($clean = self::normalise($platform, $url))) {
                $out[] = ['platform' => $platform, 'label' => self::label($platform), 'url' => $clean];
            }
        }

        return $out;
    }
}
