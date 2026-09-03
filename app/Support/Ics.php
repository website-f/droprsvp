<?php

namespace App\Support;

use App\Models\Event;
use Carbon\CarbonInterface;

/**
 * Build calendar artefacts for an event: a downloadable .ics (Apple Calendar,
 * Outlook, everything) and an "Add to Google Calendar" template URL. Times are
 * emitted in UTC (Z) so they're unambiguous across every client.
 */
class Ics
{
    /** A full VCALENDAR document for one event. */
    public static function forEvent(Event $event): string
    {
        $start = $event->starts_at ? $event->starts_at->copy()->utc() : now()->utc();
        $end = $event->ends_at ? $event->ends_at->copy()->utc() : $start->copy()->addHours(2);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//DropRSVP//Events//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:event-'.$event->id.'@'.parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.self::stamp(now()->utc()),
            'DTSTART:'.self::stamp($start),
            'DTEND:'.self::stamp($end),
            'SUMMARY:'.self::escape($event->title),
            'DESCRIPTION:'.self::escape(self::description($event)),
            'LOCATION:'.self::escape(self::location($event)),
            'URL:'.self::url($event),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        // RFC 5545 wants CRLF line endings and 75-octet folding.
        return collect($lines)->map(fn ($l) => self::fold($l))->implode("\r\n")."\r\n";
    }

    /** A one-click "Add to Google Calendar" URL. */
    public static function googleUrl(Event $event): string
    {
        $start = $event->starts_at ? $event->starts_at->copy()->utc() : now()->utc();
        $end = $event->ends_at ? $event->ends_at->copy()->utc() : $start->copy()->addHours(2);

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => self::stamp($start).'/'.self::stamp($end),
            'details' => self::description($event),
            'location' => self::location($event),
        ]);
    }

    private static function description(Event $event): string
    {
        $parts = [];
        if (filled($event->subtitle)) {
            $parts[] = $event->subtitle;
        }
        $parts[] = 'Details & tickets: '.self::url($event);

        return implode("\n", $parts);
    }

    private static function location(Event $event): string
    {
        if ($event->is_online) {
            return $event->online_url ?: 'Online event';
        }

        return collect([$event->venue_name, $event->venue_address, $event->city])->filter()->implode(', ') ?: 'Venue to be confirmed';
    }

    private static function url(Event $event): string
    {
        return rtrim((string) config('app.url'), '/').'/en-my/e/'.$event->slug;
    }

    private static function stamp(CarbonInterface $dt): string
    {
        return $dt->format('Ymd\THis\Z');
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $value);
    }

    /** Fold long content lines to 75 octets with a leading space on continuations. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $chunk = 75;
        while (strlen($line) > $chunk) {
            $out .= substr($line, 0, $chunk)."\r\n ";
            $line = substr($line, $chunk);
            $chunk = 74; // account for the leading space on folded lines
        }

        return $out.$line;
    }
}
