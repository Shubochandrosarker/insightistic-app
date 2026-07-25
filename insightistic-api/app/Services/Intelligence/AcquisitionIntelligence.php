<?php

namespace App\Services\Intelligence;

use App\Models\Site;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Channel, anomaly and performance-correlation analytics (spec Phase 7).
 */
class AcquisitionIntelligence
{
    /**
     * Revenue and conversions by acquisition channel.
     *
     * This is last-click attribution, because that is what GA4's channel
     * dimension reports and this platform stores. It is labelled as such: a
     * table that silently presents last-click as "attribution" invites people
     * to cut the channels that start journeys rather than finish them.
     */
    public function channels(Site $site, DateRange $range, int $limit = 12): array
    {
        $rows = DB::table('traffic_dimension_snapshots')
            ->where('site_id', $site->id)
            ->where('dimension_type', 'channel')
            ->where('period_start', '>=', $range->dateFrom())
            ->where('period_end', '<=', $range->dateTo())
            ->groupBy('dimension_value')
            ->selectRaw('dimension_value')
            ->selectRaw('SUM(sessions) as sessions')
            ->selectRaw('SUM(users) as users')
            ->selectRaw('SUM(conversions) as conversions')
            ->selectRaw('SUM(COALESCE(revenue, 0)) as revenue')
            ->orderByDesc('sessions')
            ->get();

        if ($rows->isEmpty()) {
            return ['has_data' => false, 'channels' => [], 'model' => 'last_click'];
        }

        $totalSessions = (int) $rows->sum('sessions');
        $totalRevenue = (float) $rows->sum('revenue');
        $totalConversions = (int) $rows->sum('conversions');

        $channels = $rows->take($limit)->map(function ($r) use ($totalSessions, $totalRevenue) {
            $sessions = (int) $r->sessions;
            $conversions = (int) $r->conversions;
            $revenue = (float) $r->revenue;

            return [
                'channel'         => $r->dimension_value,
                'sessions'        => $sessions,
                'users'           => (int) $r->users,
                'session_share'   => $totalSessions > 0 ? round($sessions / $totalSessions * 100, 1) : 0.0,
                'conversions'     => $conversions,
                'conversion_rate' => $sessions > 0 ? round($conversions / $sessions * 100, 2) : null,
                'revenue'         => round($revenue, 2),
                'revenue_share'   => $totalRevenue > 0 ? round($revenue / $totalRevenue * 100, 1) : null,
                'revenue_per_session' => $sessions > 0 ? round($revenue / $sessions, 2) : null,
            ];
        })->values()->all();

        return [
            'has_data'    => true,
            'model'       => 'last_click',
            'caveat'      => 'GA4 reports last-click channel attribution. Channels that assist earlier in a journey are undercredited here.',
            'sessions'    => $totalSessions,
            'conversions' => $totalConversions,
            'revenue'     => round($totalRevenue, 2),
            'channels'    => $channels,
        ];
    }

    /**
     * Days whose traffic sits far outside the site's own normal range.
     *
     * Uses a rolling median and median absolute deviation rather than a mean
     * and standard deviation: a single launch-day spike would inflate the
     * mean and the deviation together, which is exactly how a naive z-score
     * detector goes quiet right after the event you most wanted it to notice.
     *
     * MAD is scaled by 1.4826 so the threshold is comparable to a standard
     * deviation on normally distributed data.
     */
    public const MAD_SCALE = 1.4826;

    public function trafficAnomalies(Site $site, DateRange $range, float $threshold = 3.0): array
    {
        $rows = DB::table('traffic_daily')
            ->where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])
            ->groupBy('date')
            ->selectRaw('date, SUM(sessions) as sessions, SUM(conversions) as conversions, SUM(COALESCE(revenue, 0)) as revenue')
            ->orderBy('date')
            ->get();

        // Fourteen days is the floor for a median to describe "normal" at all.
        if ($rows->count() < 14) {
            return [
                'has_data'  => false,
                'reason'    => 'not_enough_history',
                'days'      => $rows->count(),
                'minimum'   => 14,
                'anomalies' => [],
            ];
        }

        $sessions = $rows->map(fn ($r) => (int) $r->sessions)->all();
        $median = $this->median($sessions);
        $mad = $this->median(array_map(fn ($v) => abs($v - $median), $sessions)) * self::MAD_SCALE;

        if ($mad <= 0) {
            // Perfectly flat traffic — every day is normal by definition.
            return [
                'has_data'  => true,
                'baseline'  => ['median_sessions' => $median, 'deviation' => 0.0],
                'anomalies' => [],
            ];
        }

        $anomalies = [];

        foreach ($rows as $r) {
            $value = (int) $r->sessions;
            $score = ($value - $median) / $mad;

            if (abs($score) < $threshold) {
                continue;
            }

            $anomalies[] = [
                'date'        => (string) $r->date,
                'sessions'    => $value,
                'expected'    => (int) round($median),
                'deviation'   => round($score, 1),
                'direction'   => $score > 0 ? 'spike' : 'drop',
                'change_pct'  => $median > 0 ? round(($value - $median) / $median * 100, 1) : null,
                'conversions' => (int) $r->conversions,
                'revenue'     => round((float) $r->revenue, 2),
            ];
        }

        usort($anomalies, fn ($a, $b) => abs($b['deviation']) <=> abs($a['deviation']));

        return [
            'has_data'  => true,
            'method'    => 'median_absolute_deviation',
            'threshold' => $threshold,
            'baseline'  => ['median_sessions' => (int) round($median), 'deviation' => round($mad, 1)],
            'days'      => $rows->count(),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Whether page speed and revenue move together on this site.
     *
     * Reports Pearson's r between each day's performance score and that day's
     * revenue, plus the sample size — and says outright that this is
     * correlation. Both metrics move with traffic and season, so a strong r
     * here is a reason to run an experiment, not evidence that speed caused
     * the revenue.
     *
     * Fewer than ten paired days produces a coefficient that swings wildly on
     * one extra data point, so below that it reports insufficient data rather
     * than a number people would quote.
     */
    public const CORRELATION_MIN_DAYS = 10;

    public function performanceRevenueCorrelation(Site $site, DateRange $range): array
    {
        $runs = DB::table('performance_runs')
            ->where('site_id', $site->id)
            ->where('status', 'complete')
            ->whereNotNull('performance_score')
            ->whereBetween('run_at', [$range->utcStart(), $range->utcEnd()])
            ->selectRaw('DATE(run_at) as day, AVG(performance_score) as score, AVG(lcp_ms) as lcp')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        if ($runs->isEmpty()) {
            return ['has_data' => false, 'reason' => 'no_performance_runs'];
        }

        $revenue = DB::table('wc_orders')
            ->where('site_id', $site->id)
            ->whereIn('status', ['completed', 'processing'])
            ->whereBetween('created_at_store', [$range->utcStart(), $range->utcEnd()])
            ->selectRaw('DATE(created_at_store) as day, SUM(total - COALESCE(refund_total, 0)) as revenue, COUNT(*) as orders')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $pairs = [];

        foreach ($runs as $day => $run) {
            if (! isset($revenue[$day])) {
                continue;
            }

            $pairs[] = [
                'day'     => (string) $day,
                'score'   => (float) $run->score,
                'lcp_ms'  => $run->lcp !== null ? (float) $run->lcp : null,
                'revenue' => (float) $revenue[$day]->revenue,
                'orders'  => (int) $revenue[$day]->orders,
            ];
        }

        if (count($pairs) < self::CORRELATION_MIN_DAYS) {
            return [
                'has_data'    => false,
                'reason'      => 'not_enough_paired_days',
                'paired_days' => count($pairs),
                'minimum'     => self::CORRELATION_MIN_DAYS,
            ];
        }

        $scores = array_column($pairs, 'score');
        $revenues = array_column($pairs, 'revenue');
        $r = $this->pearson($scores, $revenues);

        $lcpPairs = array_values(array_filter($pairs, fn ($p) => $p['lcp_ms'] !== null));
        $lcpR = count($lcpPairs) >= self::CORRELATION_MIN_DAYS
            ? $this->pearson(array_column($lcpPairs, 'lcp_ms'), array_column($lcpPairs, 'revenue'))
            : null;

        return [
            'has_data'    => true,
            'paired_days' => count($pairs),
            'caveat'      => 'Correlation, not causation — both metrics move with traffic and season. Treat a strong relationship as a reason to test, not as proof.',
            'performance_score_vs_revenue' => [
                'r'        => $r !== null ? round($r, 3) : null,
                'strength' => $this->strength($r),
            ],
            // Negative r here is the expected direction: slower LCP, less revenue.
            'lcp_vs_revenue' => $lcpR !== null ? [
                'r'        => round($lcpR, 3),
                'strength' => $this->strength($lcpR),
            ] : null,
            'series' => $pairs,
        ];
    }

    // ------------------------------------------------------------------

    private function pearson(array $x, array $y): ?float
    {
        $n = count($x);

        if ($n < 2 || $n !== count($y)) {
            return null;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $num = 0.0;
        $denX = 0.0;
        $denY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $num += $dx * $dy;
            $denX += $dx ** 2;
            $denY += $dy ** 2;
        }

        if ($denX <= 0 || $denY <= 0) {
            return null; // one series is constant; correlation is undefined
        }

        return $num / sqrt($denX * $denY);
    }

    private function strength(?float $r): string
    {
        if ($r === null) {
            return 'undefined';
        }

        return match (true) {
            abs($r) >= 0.7 => 'strong',
            abs($r) >= 0.4 => 'moderate',
            abs($r) >= 0.2 => 'weak',
            default        => 'negligible',
        };
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);

        if ($n === 0) {
            return 0.0;
        }

        $mid = (int) floor($n / 2);

        return $n % 2 === 0
            ? ((float) $values[$mid - 1] + (float) $values[$mid]) / 2
            : (float) $values[$mid];
    }
}
