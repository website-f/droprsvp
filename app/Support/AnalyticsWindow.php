<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * A resolved date-range for the analytics pages. Parses a `period` preset
 * (7d / 30d / 90d / 12m), a calendar period (today, this or last week, this or
 * last month, this year), `all` where the page allows it, or an explicit
 * `custom` from/to range into a window
 * the aggregation helpers can bucket by day (short ranges) or month (long ones).
 * All inputs are sanitised here so the rest of the code trusts the window.
 */
class AnalyticsWindow
{
    /** Preset key → number of days back (inclusive of today). */
    public const PRESETS = ['7d' => 7, '30d' => 30, '90d' => 90, '12m' => 365];

    public const DEFAULT = '30d';

    /** Calendar periods: the current or previous week/month, Monday-first weeks. */
    public const CALENDAR = ['today', 'week', 'last_week', 'month', 'last_month', 'year'];

    /**
     * @return array{period:string,from:CarbonInterface,to:CarbonInterface,from_date:string,to_date:string,bucket:'day'|'month',days:int,label:string}
     */
    public static function fromRequest(Request $request, string $default = self::DEFAULT, ?CarbonInterface $since = null): array
    {
        return self::resolve(
            (string) $request->query('period', $default),
            $request->query('from'),
            $request->query('to'),
            $since,
        );
    }

    /** @return array{period:string,from:CarbonInterface,to:CarbonInterface,from_date:string,to_date:string,bucket:'day'|'month',days:int,label:string} */
    public static function resolve(string $period, mixed $fromRaw = null, mixed $toRaw = null, ?CarbonInterface $since = null): array
    {
        $to = today()->endOfDay();
        $from = null;

        // "All time" only where the page says when time starts (its first
        // sale), so the range and its buckets stay finite.
        if ($period === 'all' && $since) {
            $from = $since->copy()->startOfDay()->min(today()->startOfDay());
            $spanDays = (int) $from->diffInDays($to) + 1;

            return [
                'period' => 'all', 'from' => $from, 'to' => $to,
                'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
                'bucket' => $spanDays > 92 ? 'month' : 'day', 'days' => $spanDays, 'label' => 'All time',
            ];
        }

        if (in_array($period, self::CALENDAR, true)) {
            [$from, $to] = match ($period) {
                'today' => [today()->startOfDay(), today()->endOfDay()],
                'week' => [today()->startOfWeek(CarbonInterface::MONDAY), today()->endOfDay()],
                'last_week' => [today()->startOfWeek(CarbonInterface::MONDAY)->subWeek(), today()->startOfWeek(CarbonInterface::MONDAY)->subDay()->endOfDay()],
                'month' => [today()->startOfMonth(), today()->endOfDay()],
                'last_month' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
                'year' => [today()->startOfYear(), today()->endOfDay()],
            };
        }

        if ($period === 'custom') {
            $f = self::parseDate($fromRaw);
            $t = self::parseDate($toRaw);
            if ($f && $t) {
                $from = $f->startOfDay();
                $to = $t->endOfDay();
            } else {
                $period = self::DEFAULT; // incomplete custom range → fall back
            }
        }

        if (! $from) {
            $days = self::PRESETS[$period] ?? self::PRESETS[self::DEFAULT];
            $period = isset(self::PRESETS[$period]) ? $period : self::DEFAULT;
            $from = today()->subDays($days - 1)->startOfDay();
        }

        // Keep the range sane: from ≤ to, and no wider than ~2 years of daily rows.
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        $spanDays = $from->diffInDays($to) + 1;
        if ($spanDays > 730) {
            $from = $to->copy()->subDays(729)->startOfDay();
            $spanDays = 730;
        }

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'bucket' => $spanDays > 92 ? 'month' : 'day',
            'days' => $spanDays,
            'label' => self::label($period, $from, $to),
        ];
    }

    /** The query params that reproduce this window, for links / exports. */
    public static function params(array $w): array
    {
        return $w['period'] === 'custom'
            ? ['period' => 'custom', 'from' => $w['from_date'], 'to' => $w['to_date']]
            : ['period' => $w['period']];
    }

    private static function parseDate(mixed $raw): ?CarbonInterface
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            $d = CarbonImmutable::createFromFormat('Y-m-d', trim($raw));

            return $d === false ? null : $d;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function label(string $period, CarbonInterface $from, CarbonInterface $to): string
    {
        return match ($period) {
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
            '12m' => 'Last 12 months',
            'today' => 'Today',
            'week' => 'This week',
            'last_week' => 'Last week',
            'month' => $from->format('F Y'),
            'last_month' => $from->format('F Y'),
            'year' => $from->format('Y'),
            default => $from->format('j M Y').' – '.$to->format('j M Y'),
        };
    }
}
