<?php

namespace App\Support;

use App\Models\Event;

/**
 * Lightweight {token} templating for per-event SEO. Admins can write titles /
 * descriptions like "Visit {event_name} in {city}" instead of typing the full
 * event details; the tokens are substituted with the real values at render time.
 */
class SeoTemplate
{
    /** token => human label, surfaced as clickable chips in the SEO editor. */
    public const TOKENS = [
        '{event_name}' => 'Event name',
        '{category}' => 'Category',
        '{city}' => 'City',
        '{venue}' => 'Venue',
        '{date}' => 'Date',
        '{organizer}' => 'Organizer',
        '{site}' => 'Site name',
    ];

    /** Chip list for the frontend: [{ token, label }]. */
    public static function chips(): array
    {
        return array_map(fn ($token, $label) => ['token' => $token, 'label' => $label], array_keys(self::TOKENS), array_values(self::TOKENS));
    }

    /** Resolve each token to its value for a given event. */
    public static function values(Event $event): array
    {
        return [
            '{event_name}' => (string) $event->title,
            '{category}' => (string) ($event->category?->name ?? ''),
            '{city}' => (string) ($event->city ?? ''),
            '{venue}' => $event->is_online ? 'Online' : (string) ($event->venue_name ?? ''),
            '{date}' => optional($event->starts_at)->format('j M Y') ?? '',
            '{organizer}' => (string) ($event->user?->name ?? config('seo.site_name', 'DropRSVP')),
            '{site}' => (string) config('seo.site_name', 'DropRSVP'),
        ];
    }

    /** Substitute all tokens in a template string (null-safe). Extra whitespace is tidied. */
    public static function render(?string $text, Event $event): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $out = strtr($text, self::values($event));

        // Collapse doubled spaces left by empty tokens (e.g. a blank {city}).
        return trim(preg_replace('/\s{2,}/', ' ', $out));
    }
}
