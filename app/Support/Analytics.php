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

    /**
     * Impressions + clicks over a resolved window, bucketed by day or month.
     * Works for a single event's dailyStats or a summed platform query alike
     * (the SUM + groupBy collapses either to one row per date).
     */
    public static function reach($query, array $w): array
    {
        $rows = (clone $query)->whereBetween('stat_date', [$w['from_date'], $w['to_date']])
            ->selectRaw('stat_date, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->groupBy('stat_date')->get()
            ->keyBy(fn ($r) => \Illuminate\Support\Carbon::parse((string) $r->stat_date)->toDateString());

        return self::bucketed($w, fn (\Carbon\CarbonInterface $d) => [
            'impressions' => (int) (optional($rows->get($d->toDateString()))->impressions ?? 0),
            'clicks' => (int) (optional($rows->get($d->toDateString()))->clicks ?? 0),
        ], ['impressions' => 0, 'clicks' => 0]);
    }

    /** Revenue over a resolved window (uses paid_at), bucketed by day or month. */
    public static function revenue($query, array $w): array
    {
        $rows = (clone $query)->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$w['from'], $w['to']])
            ->selectRaw('DATE(paid_at) as d, SUM(total) as t')->groupBy('d')->pluck('t', 'd');

        return self::bucketed($w, fn (\Carbon\CarbonInterface $d) => ['revenue' => round((float) ($rows[$d->toDateString()] ?? 0), 2)], ['revenue' => 0.0]);
    }

    /**
     * Roll per-day values into the window's buckets (daily rows, or summed into
     * calendar months for long ranges). $dayVal returns the metrics for one day;
     * $zero seeds a bucket's accumulator. Portable across SQLite + MySQL because
     * the month grouping happens in PHP, not SQL.
     */
    private static function bucketed(array $w, callable $dayVal, array $zero): array
    {
        // Dates are CarbonImmutable, so every step reassigns (addDay/addMonth
        // return a new instance rather than mutating in place).
        $out = [];
        $from = $w['from'];
        $to = $w['to'];

        if ($w['bucket'] === 'month') {
            $cur = $from->startOfMonth();
            $lastMonth = $to->startOfMonth();
            while ($cur->lte($lastMonth)) {
                $agg = $zero;
                $monthEnd = $cur->endOfMonth();
                for ($d = $cur; $d->lte($monthEnd); $d = $d->addDay()) {
                    if ($d->lt($from) || $d->gt($to)) {
                        continue;
                    }
                    foreach ($dayVal($d) as $k => $v) {
                        $agg[$k] += $v;
                    }
                }
                $out[] = ['date' => $cur->format('M Y')] + $agg;
                $cur = $cur->addMonth();
            }

            return $out;
        }

        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $out[] = ['date' => $d->format('j M')] + $dayVal($d);
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
