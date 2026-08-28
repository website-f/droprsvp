<?php

namespace App\Support;

/**
 * Aggregation helpers shared by the organizer + superadmin analytics pages.
 * Column names passed in are fixed constants (never user input).
 */
class Analytics
{
    public const GENDER_LABELS = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'na' => 'Prefer not to say'];
    public const SOURCE_LABELS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'friend' => 'Friend', 'search' => 'Search', 'email' => 'Email', 'other' => 'Other'];
    public const AGE_ORDER = ['under-18' => 'Under 18', '18-24' => '18–24', '25-34' => '25–34', '35-44' => '35–44', '45-54' => '45–54', '55+' => '55+'];

    /** Last $days of impressions + clicks from a daily-stats query, zero-filled. */
    public static function trend($query, int $days): array
    {
        $from = today()->subDays($days - 1);
        $rows = (clone $query)->where('stat_date', '>=', $from)->get(['stat_date', 'impressions', 'clicks'])
            ->keyBy(fn ($r) => $r->stat_date->toDateString());

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i);
            $r = $rows->get($d->toDateString());
            $out[] = ['date' => $d->format('j M'), 'impressions' => (int) ($r->impressions ?? 0), 'clicks' => (int) ($r->clicks ?? 0)];
        }

        return $out;
    }

    /** Platform reach: impressions + clicks SUMMED across all events per day, zero-filled. */
    public static function trendSummed($query, int $days): array
    {
        $from = today()->subDays($days - 1);
        $rows = (clone $query)->where('stat_date', '>=', $from)
            ->selectRaw('stat_date, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->groupBy('stat_date')->get()
            ->keyBy(fn ($r) => \Illuminate\Support\Carbon::parse((string) $r->stat_date)->toDateString());

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i);
            $r = $rows->get($d->toDateString());
            $out[] = ['date' => $d->format('j M'), 'impressions' => (int) ($r->impressions ?? 0), 'clicks' => (int) ($r->clicks ?? 0)];
        }

        return $out;
    }

    /** Revenue per day from an orders query (uses paid_at), zero-filled. */
    public static function revenueByDay($query, int $days): array
    {
        $from = today()->subDays($days - 1);
        $rows = (clone $query)->whereNotNull('paid_at')->where('paid_at', '>=', $from->copy()->startOfDay())
            ->selectRaw('DATE(paid_at) as d, SUM(total) as t')->groupBy('d')->pluck('t', 'd');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i);
            $out[] = ['date' => $d->format('j M'), 'revenue' => round((float) ($rows[$d->toDateString()] ?? 0), 2)];
        }

        return $out;
    }

    /** Count rows grouped by $column, mapped through $labels, sorted by count desc. */
    public static function breakdown($query, string $column, array $labels): array
    {
        $counts = self::counts($query, $column);

        $out = [];
        foreach ($counts as $k => $c) {
            $out[] = ['name' => $labels[$k] ?? ucfirst((string) $k), 'value' => (int) $c];
        }
        usort($out, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $out;
    }

    /** Like breakdown() but preserves a fixed key order (e.g. age bands). */
    public static function ordered($query, string $column, array $orderMap): array
    {
        $counts = self::counts($query, $column);

        $out = [];
        foreach ($orderMap as $k => $label) {
            if (isset($counts[$k])) {
                $out[] = ['name' => $label, 'value' => (int) $counts[$k]];
            }
        }

        return $out;
    }

    /** Top $limit values of $column by count (raw value as label). */
    public static function top($query, string $column, int $limit): array
    {
        $counts = self::counts($query, $column, $limit);

        $out = [];
        foreach ($counts as $k => $c) {
            $out[] = ['name' => (string) $k, 'value' => (int) $c];
        }

        return $out;
    }

    private static function counts($query, string $column, ?int $limit = null)
    {
        $q = (clone $query)->whereNotNull($column)->where($column, '!=', '')
            ->selectRaw("{$column} as k, count(*) as c")->groupBy($column)->orderByDesc('c');

        if ($limit) {
            $q->limit($limit);
        }

        return $q->pluck('c', 'k');
    }
}
