<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Setting;
use App\Models\User;

/**
 * The house style for event and organizer page titles and descriptions.
 *
 * Every event page used to get its bare title as the <title> and the first 155
 * characters of its description as the meta description. That is fine for one
 * page and terrible across hundreds: no city, no date, nothing to tell two
 * similarly-named events apart in a search result, and descriptions that trail
 * off mid-sentence.
 *
 * So there is one template per page type, applied to every page of that type,
 * with {tokens} filled in from the record. A per-event override still wins — an
 * organizer who has written their own title keeps it — and a superadmin can
 * change the house template itself in Admin → SEO.
 */
class SeoTemplate
{
    /**
     * The shipped defaults.
     *
     * Titles already carry the site name, so SeoManager will not append it
     * again (it checks before appending).
     */
    public const DEFAULTS = [
        'event_title' => '{event_name} – {city}, {short_date} | {site}',
        'event_description' => '{category} event by {organizer} at {venue}, {city} on {day_date}, {start_time}. Book now on {site} platform.',
        'organizer_title' => '{organizer} · Event Organiser | {site}',
        'organizer_description' => 'Follow {organizer} on {site} platform to discover their latest events and book your spot.',
    ];

    /** Where the per-request copy of the saved templates lives. */
    private const CACHE_KEY = 'seo.templates';

    /** token => human label, surfaced as clickable chips in the SEO editor. */
    public const TOKENS = [
        '{event_name}' => 'Event name',
        '{category}' => 'Category',
        '{city}' => 'City',
        '{state}' => 'State',
        '{venue}' => 'Venue',
        '{date}' => 'Date (10 Oct 2026)',
        '{short_date}' => 'Short date (10 Oct 2026)',
        '{day_date}' => 'Day + date (Sat, 10 Oct 2026)',
        '{start_time}' => 'Start time (3pm)',
        '{organizer}' => 'Organizer',
        '{site}' => 'Site name',
    ];

    /** The subset that means anything on an organizer page. */
    public const ORGANIZER_TOKENS = [
        '{organizer}' => 'Organizer',
        '{city}' => 'City',
        '{site}' => 'Site name',
    ];

    /** Chip list for the frontend: [{ token, label }]. */
    public static function chips(array $tokens = self::TOKENS): array
    {
        return array_map(fn ($token, $label) => ['token' => $token, 'label' => $label], array_keys($tokens), array_values($tokens));
    }

    /**
     * The house template for one slot, as edited in Admin → SEO.
     *
     * Falls back to the shipped default, so clearing the field in the admin
     * restores the standard rather than leaving pages with no title at all.
     */
    public static function house(string $key): string
    {
        // Memoised, because a page reads two of these and without it every
        // public event and organizer page costs an extra settings query.
        //
        // On the CONTAINER, not in a static: a static would outlive the request
        // and an Octane worker would then serve the templates it happened to
        // read first, never noticing an edit.
        if (app()->bound(self::CACHE_KEY)) {
            $saved = app(self::CACHE_KEY);
        } else {
            $saved = Setting::getArray('seo_templates', []);
            app()->instance(self::CACHE_KEY, $saved);
        }

        $value = trim((string) ($saved[$key] ?? ''));

        return $value !== '' ? $value : (self::DEFAULTS[$key] ?? '');
    }

    /** Drop the memoised copy — called right after a save, in the same request. */
    public static function forget(): void
    {
        app()->forgetInstance(self::CACHE_KEY);
    }

    /** All four, for the admin editor. @return array<string,string> */
    public static function allHouse(): array
    {
        return collect(array_keys(self::DEFAULTS))
            ->mapWithKeys(fn ($key) => [$key => self::house($key)])
            ->all();
    }

    /** Resolve each token to its value for a given event. */
    public static function values(Event $event): array
    {
        // In the EVENT's timezone, not the server's. An event at 3pm in Kuala
        // Lumpur was showing as 11pm once before, because UTC timestamps were
        // being formatted without converting first.
        $starts = $event->starts_at?->setTimezone($event->timezone ?: config('app.timezone'));
        $city = (string) ($event->city ?? '');

        return [
            '{event_name}' => (string) $event->title,
            '{category}' => (string) ($event->category?->name ?? ''),
            '{city}' => $city,
            '{state}' => $city ? (string) (Cities::stateForCity($city) ?? '') : '',
            '{venue}' => $event->is_online ? 'Online' : (string) ($event->venue_name ?? ''),
            '{date}' => $starts?->format('j M Y') ?? '',
            '{short_date}' => $starts?->format('j M Y') ?? '',
            '{day_date}' => $starts?->format('D, j M Y') ?? '',
            '{start_time}' => $starts ? self::time($starts) : '',
            '{organizer}' => (string) ($event->user?->name ?? config('seo.site_name', 'DropRSVP')),
            '{site}' => (string) config('seo.site_name', 'DropRSVP'),
        ];
    }

    /** Token values for an organizer profile. */
    public static function organizerValues(User $organizer): array
    {
        $profile = $organizer->relationLoaded('organizerProfile')
            ? $organizer->organizerProfile
            : $organizer->organizerProfile()->first();

        return [
            // The business name is what they trade as, so prefer it — it is what
            // someone would actually search for.
            '{organizer}' => (string) ($profile?->business_name ?: $organizer->name),
            // OrganizerProfile has no city of its own; the account does.
            '{city}' => (string) ($organizer->city ?? ''),
            '{site}' => (string) config('seo.site_name', 'DropRSVP'),
        ];
    }

    /** Substitute all tokens in a template string (null-safe). */
    public static function render(?string $text, Event $event): ?string
    {
        return self::apply($text, self::values($event));
    }

    /** Substitute organizer tokens in a template string (null-safe). */
    public static function renderOrganizer(?string $text, User $organizer): ?string
    {
        return self::apply($text, self::organizerValues($organizer));
    }

    /** Render one of the house templates for an event. */
    public static function forEvent(string $key, Event $event): ?string
    {
        return self::render(self::house($key), $event);
    }

    /** Render one of the house templates for an organizer. */
    public static function forOrganizer(string $key, User $organizer): ?string
    {
        return self::renderOrganizer(self::house($key), $organizer);
    }

    private static function apply(?string $text, array $values): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return self::assemble($text, $values);
    }

    /**
     * Fill a template in, removing the glue around any token that resolved to
     * nothing.
     *
     * Templates are written for the complete case: "{event_name} – {city},
     * {short_date}". Plain substitution on an event with no city gives
     * "Neon Nights –, 10 Oct 2026", and on an online event with no venue
     * "at , Kajang on …" — both of which look broken in a search result.
     *
     * So an empty token is not replaced with "", it is replaced with a marker,
     * and each marker is then removed TOGETHER WITH whatever introduced it: the
     * preposition, dash or comma immediately before it. Taking the preceding
     * glue rather than the following punctuation is what keeps the separator
     * that still has work to do — "Neon Nights, 10 Oct 2026" rather than
     * "Neon Nights 10 Oct 2026".
     */
    private static function assemble(string $template, array $values): string
    {
        $marker = "\x00";

        $filled = strtr($template, array_map(
            fn ($value) => trim((string) $value) === '' ? $marker : trim((string) $value),
            $values,
        ));

        // Anything that joins one value to the next, and so has no reason to
        // survive when the value after it is gone.
        $glue = '(?:\s*(?:\b(?:at|in|on|by|for|from)\b|[,\-\x{2013}|\x{00b7}])\s*)';

        // Looped, because two empty tokens in a row each need their own pass.
        do {
            $previous = $filled;
            $filled = preg_replace('/'.$glue.'?'.$marker.'/u', '', $filled) ?? $filled;
        } while ($filled !== $previous);

        $filled = preg_replace('/\s{2,}/u', ' ', $filled) ?? $filled;
        $filled = preg_replace('/\s+([,.])/u', '$1', $filled) ?? $filled;
        // Punctuation left stranded at either end by a token that was there.
        $filled = preg_replace('/^[\s,\-\x{2013}|\x{00b7}]+/u', '', $filled) ?? $filled;
        $filled = preg_replace('/[\s,\-\x{2013}|\x{00b7}]+$/u', '', $filled) ?? $filled;

        $filled = trim($filled);

        // Only when the template OPENED with a token that resolved to nothing,
        // as "{category} event by …" does for an uncategorised event, leaving a
        // lower-case first word. Capitalising unconditionally would also
        // rewrite values that are not sentences — a keywords list came back as
        // "Neon, rooftop, live music".
        $opensWithToken = preg_match('/^(\{[a-z_]+\})/', $template, $m) === 1;

        return $opensWithToken && trim((string) ($values[$m[1]] ?? '')) === ''
            ? ucfirst($filled)
            : $filled;
    }

    /** "3pm", "3.30pm" — how a time is written on a Malaysian event listing. */
    private static function time(\DateTimeInterface $at): string
    {
        $suffix = strtolower($at->format('a'));

        return $at->format('i') === '00'
            ? $at->format('g').$suffix
            : $at->format('g.i').$suffix;
    }
}
