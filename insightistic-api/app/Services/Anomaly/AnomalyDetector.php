<?php

namespace App\Services\Anomaly;

use App\Models\Site;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Generalizes the median/MAD anomaly math
 * `App\Services\Intelligence\AcquisitionIntelligence::trafficAnomalies()`
 * already shipped for GA4 sessions specifically, to a small fixed set of
 * metrics across all three query-engine sources (spec: Anomaly Engine, P7).
 * See docs/ANOMALY-ENGINE.md.
 *
 * Deliberately does NOT extract/share code with AcquisitionIntelligence —
 * that class's `trafficAnomalies()` has its own shipped, tested output
 * shape (`docs/CONVERSATIONAL-ANALYTICS-AUDIT.md`-era feature) that this
 * class must not risk regressing. This mirrors an existing convention in
 * this codebase: `WooCommerceQueryCompiler`, `Ga4QueryCompiler` and
 * `GscQueryCompiler` each independently hardcode their own table/column
 * maps rather than importing a shared one, on the same "small independent
 * copy over a risky shared abstraction for core logic" reasoning.
 *
 * Uses a rolling median and median absolute deviation rather than a mean
 * and standard deviation for the identical reason AcquisitionIntelligence
 * documents: a single spike would inflate a mean-based detector's own
 * threshold right when it matters most. MAD is scaled by 1.4826 so the
 * resulting score is comparable to a standard-deviation z-score on
 * normally distributed data.
 *
 * Every SUPPORTED_METRICS table/column is a hardcoded literal in this
 * file, never read out of the Semantic Metric Registry at runtime — same
 * boundary every query-engine source adapter enforces.
 */
class AnomalyDetector
{
    public const MAD_SCALE = 1.4826;

    public const MIN_HISTORY_DAYS = 14;

    /** metric id => [source, table, column, ga4-only: filtered to source='ga4']. The only metrics this detector can ever compute. */
    private const SUPPORTED_METRICS = [
        'net_revenue' => ['source' => 'woocommerce', 'table' => 'metric_snapshots', 'column' => 'revenue'],
        'orders' => ['source' => 'woocommerce', 'table' => 'metric_snapshots', 'column' => 'orders'],
        'refund_count' => ['source' => 'woocommerce', 'table' => 'metric_snapshots', 'column' => 'refunds'],
        'ga4_sessions' => ['source' => 'ga4', 'table' => 'traffic_daily', 'column' => 'sessions'],
        'ga4_conversions' => ['source' => 'ga4', 'table' => 'traffic_daily', 'column' => 'conversions'],
        'organic_clicks' => ['source' => 'gsc', 'table' => 'seo_daily', 'column' => 'clicks'],
        'organic_impressions' => ['source' => 'gsc', 'table' => 'seo_daily', 'column' => 'impressions'],
    ];

    /** @return string[] */
    public static function supportedMetrics(): array
    {
        return array_keys(self::SUPPORTED_METRICS);
    }

    public static function isSupported(string $metricId): bool
    {
        return isset(self::SUPPORTED_METRICS[$metricId]);
    }

    /**
     * @return array{has_data: bool, reason?: string, method?: string, threshold?: float, baseline?: array{median: float, deviation: float}, days: int, anomalies: array<int, array{date: string, value: float, expected: float, score: float, direction: string, change_pct: ?float}>}
     */
    public function detect(Site $site, string $metricId, DateRange $range, float $threshold = 3.0): array
    {
        if (! self::isSupported($metricId)) {
            throw new \InvalidArgumentException("Anomaly detection is not supported for metric \"{$metricId}\".");
        }

        $rows = $this->dailySeries($site, $metricId, $range);

        if ($rows->count() < self::MIN_HISTORY_DAYS) {
            return [
                'has_data' => false,
                'reason' => 'not_enough_history',
                'days' => $rows->count(),
                'minimum' => self::MIN_HISTORY_DAYS,
                'anomalies' => [],
            ];
        }

        $values = $rows->map(fn ($r) => (float) $r->value)->all();
        $median = $this->median($values);
        $mad = $this->median(array_map(fn ($v) => abs($v - $median), $values)) * self::MAD_SCALE;

        if ($mad <= 0) {
            // Perfectly flat over the window — every day is normal by definition.
            return [
                'has_data' => true,
                'method' => 'median_absolute_deviation',
                'threshold' => $threshold,
                'baseline' => ['median' => $median, 'deviation' => 0.0],
                'days' => $rows->count(),
                'anomalies' => [],
            ];
        }

        $anomalies = [];
        foreach ($rows as $r) {
            $value = (float) $r->value;
            $score = ($value - $median) / $mad;

            if (abs($score) < $threshold) {
                continue;
            }

            $anomalies[] = [
                'date' => (string) $r->date,
                'value' => $value,
                'expected' => round($median, 2),
                'score' => round($score, 2),
                'direction' => $score > 0 ? 'spike' : 'drop',
                'change_pct' => $median > 0 ? round(($value - $median) / $median * 100, 1) : null,
            ];
        }

        usort($anomalies, fn ($a, $b) => abs($b['score']) <=> abs($a['score']));

        return [
            'has_data' => true,
            'method' => 'median_absolute_deviation',
            'threshold' => $threshold,
            'baseline' => ['median' => round($median, 2), 'deviation' => round($mad, 2)],
            'days' => $rows->count(),
            'anomalies' => $anomalies,
        ];
    }

    private function dailySeries(Site $site, string $metricId, DateRange $range)
    {
        $def = self::SUPPORTED_METRICS[$metricId];

        $query = DB::table($def['table'])
            ->where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()]);

        if ($def['source'] === 'ga4') {
            // traffic_daily stores more than one source per (site, date) —
            // same reasoning Ga4QueryCompiler documents.
            $query->where('source', 'ga4');
        }

        return $query->groupBy('date')
            ->selectRaw("date, SUM({$def['column']}) as value")
            ->orderBy('date')
            ->get();
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
