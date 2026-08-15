<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\Site;
use App\Services\Automation\AutomationDispatcher;
use App\Support\DateRange;

/**
 * Site-level orchestrator for the Anomaly Engine (spec: P7) — mirrors
 * `AlertDetector::scan()`'s shape exactly: run every detector, persist
 * only genuinely new findings, dedup on re-scan.
 *
 * Every `AnomalyDetector::supportedMetrics()` id is scanned over a rolling
 * 30-day window (comfortably above `AnomalyDetector::MIN_HISTORY_DAYS`).
 * A day already recorded for a (site, metric) is never re-persisted —
 * `anomalies`' `unique(site_id, metric_id, date)` constraint enforces this
 * at the database level too, not just in application logic.
 */
class AnomalyScanner
{
    private const WINDOW_PRESET = 'last_30_days';

    private const DEFAULT_THRESHOLD = 3.0;

    public function __construct(
        private readonly AnomalyDetector $detector,
        private readonly AutomationDispatcher $dispatcher,
    ) {}

    /** @return Anomaly[] Newly created anomalies this run (already-recorded days are skipped, not returned). */
    public function scan(Site $site, float $threshold = self::DEFAULT_THRESHOLD): array
    {
        $range = DateRange::fromRequest(self::WINDOW_PRESET, null, null, $site->timezone ?: config('app.timezone', 'UTC'));
        $created = [];

        foreach (AnomalyDetector::supportedMetrics() as $metricId) {
            $result = $this->detector->detect($site, $metricId, $range, $threshold);
            if (! $result['has_data']) {
                continue;
            }

            foreach ($result['anomalies'] as $finding) {
                $anomaly = $this->persist($site, $metricId, $finding, $result['baseline']);
                if ($anomaly === null) {
                    continue; // already recorded for this (site, metric, date) — dedup
                }

                $created[] = $anomaly;

                // Automation Studio (P6) 'anomaly_detected' trigger — only
                // for a genuinely new row, same "don't re-fire on every
                // scan" rule AlertDetector's own hook follows.
                $this->dispatcher->dispatch('anomaly_detected', $site, [
                    'metric_id' => $metricId,
                    'anomaly_id' => $anomaly->id,
                ]);
            }
        }

        return $created;
    }

    /** @param array{median: float, deviation: float} $baseline */
    private function persist(Site $site, string $metricId, array $finding, array $baseline): ?Anomaly
    {
        $existing = Anomaly::query()
            ->where('site_id', $site->id)
            ->where('metric_id', $metricId)
            ->where('date', $finding['date'])
            ->first();

        if ($existing) {
            return null;
        }

        return Anomaly::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'metric_id' => $metricId,
            'date' => $finding['date'],
            'value' => $finding['value'],
            'baseline_median' => $baseline['median'],
            'mad' => $baseline['deviation'],
            'score' => $finding['score'],
            'direction' => $finding['direction'],
            'severity' => $this->severity(abs($finding['score'])),
            'is_acknowledged' => false,
            'detected_at' => now(),
        ]);
    }

    /** Bands relative to the default detection threshold (3.0) — a score just past the threshold is "low", a wildly outlying one is "high". */
    private function severity(float $absScore): string
    {
        return match (true) {
            $absScore >= 6.0 => 'high',
            $absScore >= 4.0 => 'medium',
            default => 'low',
        };
    }
}
