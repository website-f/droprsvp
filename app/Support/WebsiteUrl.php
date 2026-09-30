<?php

namespace App\Support;

/**
 * Tidy a website a person typed into a form.
 *
 * The field is optional, but the `url` validation rule is not forgiving: it
 * rejects "instagram.com/3dexpress" and "www.example.com" because neither
 * carries a scheme. So an organizer who filled it in the way everyone writes a
 * web address got a validation error they could not clear without knowing to
 * type "https://" — which makes an optional field behave like a required one
 * you cannot satisfy.
 *
 * Normalising first means we accept what they meant. Empty stays empty.
 */
final class WebsiteUrl
{
    /** Schemes we will store. Anything else is not a website. */
    private const ALLOWED = ['http', 'https'];

    /**
     * @return string|null The normalised URL, or null when there is nothing
     *                     usable — which for an optional field means "blank".
     */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // A bare host, the way people write it.
        if (! preg_match('~^[a-z][a-z0-9+.\-]*:~i', $trimmed)) {
            $trimmed = 'https://'.ltrim($trimmed, '/');
        }

        $parts = parse_url($trimmed);

        // Reject javascript:, data: and friends outright. This value ends up in
        // an href on a public profile, so anything but http(s) is an attack.
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), self::ALLOWED, true)) {
            return null;
        }

        // A scheme with no host ("https://") is not an address.
        if (($parts['host'] ?? '') === '') {
            return null;
        }

        return $trimmed;
    }
}
