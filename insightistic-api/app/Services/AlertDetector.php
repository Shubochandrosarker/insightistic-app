<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\BrokenLinkSnapshot;
use App\Models\MetricSnapshot;
use App\Models\PerformanceRun;
use App\Models\SeoDaily;
use App\Models\Site;
use App\Models\SyncLog;
use App\Models\TrafficDaily;
use App\Models\WcProduct;
use App\Support\DateRange;
use Illuminate\Support\Carbon;

/**
 * Deterministic alert rules (spec §12). AI may explain an alert elsewhere
 * (contextual insights can reference open alerts — see ContextAssembler::
 * alerts()) but must never be the only detector; every rule here is a plain
 * threshold check against real data, run per site.
 *
 * Dedup: an unresolved alert of the same (site_id, type) is never
 * duplicated — detect() only creates a new row when no open one already
 * exists, and auto-resolves the open one the moment its condition clears.
 * Manual resolve/reopen (AlertController) still works independently of this
 * — a user can resolve early, and detect() will only reopen it by creating
 * a fresh row if the condition is still true on a later run.
 */
class AlertDetector
{
    private const REVENUE_DROP_THRESHOLD = -20.0;   // % vs previous period
    private const REFUND_SPIKE_THRESHOLD = 3;        // absolute refunds in period
    private const FAILED_ORDER_THRESHOLD = 3;         // absolute failed orders in period
    private const TRAFFIC_CHANGE_THRESHOLD = 30.0;    // % vs previous period, either direction
    private const SEO_DECLINE_THRESHOLD = -25.0;      // % clicks vs previous period
    private const PERFORMANCE_REGRESSION_POINTS = 15;  // score points dropped vs previous run
    private const SYNC_STALE_HOURS = 48;
    private const BROKEN_LINK_SPIKE_HITS = 10;         // hits in the window for a single path

    /** Run every rule for one site. Returns the alerts created this run. */
    public function scan(Site $site): array
    {
        $range = DateRange::fromRequest('last_7_days', null, null, $site->timezone ?: config('app.timezone', 'UTC'));
        $created = [];

        $created[] = $this->revenueDrop($site, $range);
        $created[] = $this->refundSpike($site, $range);
        $created[] = $this->failedOrderSpike($site, $range);
        $created[] = $this->lowStock($site);
        $created[] = $this->trafficChange($site, $range);
        $created[] = $this->seoDecline($site, $range);
        $created[] = $this->performanceRegression($site);
        $created[] = $this->syncStale($site);
        $created[] = $this->brokenLinkSpike($site, $range);

        return array_values(array_filter($created));
    }

    private function revenueDrop(Site $site, DateRange $range): ?Alert
    {
        $current = (float) MetricSnapshot::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])->sum('revenue');
        $previous = (float) MetricSnapshot::where('site_id', $site->id)
            ->whereBetween('date', [$range->previous()->dateFrom(), $range->previous()->dateTo()])->sum('revenue');

        if ($previous <= 0) {
            return $this->clear($site, 'revenue_drop');
        }

        $change = (($current - $previous) / $previous) * 100;

        return $change <= self::REVENUE_DROP_THRESHOLD
            ? $this->raise($site, 'revenue_drop', 'Revenue dropped this week', sprintf('Revenue is down %.0f%% vs the previous 7 days (%.2f vs %.2f).', abs($change), $current, $previous), 'high')
            : $this->clear($site, 'revenue_drop');
    }

    private function refundSpike(Site $site, DateRange $range): ?Alert
    {
        $refunds = (int) MetricSnapshot::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])->sum('refunds');

        return $refunds >= self::REFUND_SPIKE_THRESHOLD
            ? $this->raise($site, 'refund_spike', 'Refunds spiked this week', "{$refunds} refunds in the last 7 days — review recently affected products.", 'medium')
            : $this->clear($site, 'refund_spike');
    }

    private function failedOrderSpike(Site $site, DateRange $range): ?Alert
    {
        $failed = (int) MetricSnapshot::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])->sum('failed_orders');

        return $failed >= self::FAILED_ORDER_THRESHOLD
            ? $this->raise($site, 'failed_order_spike', 'Failed orders detected', "{$failed} failed orders in the last 7 days — check the payment gateway.", 'medium')
            : $this->clear($site, 'failed_order_spike');
    }

    private function lowStock(Site $site): ?Alert
    {
        $out = WcProduct::where('site_id', $site->id)->where('stock_status', 'outofstock')->count();

        return $out > 0
            ? $this->raise($site, 'low_stock', 'Products out of stock', "{$out} product(s) are out of stock.", 'medium')
            : $this->clear($site, 'low_stock');
    }

    private function trafficChange(Site $site, DateRange $range): ?Alert
    {
        $current = (int) TrafficDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])->sum('sessions');
        $previous = (int) TrafficDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->previous()->dateFrom(), $range->previous()->dateTo()])->sum('sessions');

        if ($previous <= 0) {
            return $this->clear($site, 'traffic_drop') ?? $this->clear($site, 'traffic_spike');
        }

        $change = (($current - $previous) / $previous) * 100;

        if ($change <= -self::TRAFFIC_CHANGE_THRESHOLD) {
            $this->clear($site, 'traffic_spike');
            return $this->raise($site, 'traffic_drop', 'Traffic dropped', sprintf('Sessions are down %.0f%% vs the previous 7 days.', abs($change)), 'high');
        }
        if ($change >= self::TRAFFIC_CHANGE_THRESHOLD) {
            $this->clear($site, 'traffic_drop');
            return $this->raise($site, 'traffic_spike', 'Traffic spiked', sprintf('Sessions are up %.0f%% vs the previous 7 days.', $change), 'low');
        }

        $this->clear($site, 'traffic_drop');
        $this->clear($site, 'traffic_spike');
        return null;
    }

    private function seoDecline(Site $site, DateRange $range): ?Alert
    {
        $current = (int) SeoDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->dateFrom(), $range->dateTo()])->sum('clicks');
        $previous = (int) SeoDaily::where('site_id', $site->id)
            ->whereBetween('date', [$range->previous()->dateFrom(), $range->previous()->dateTo()])->sum('clicks');

        if ($previous <= 0) {
            return $this->clear($site, 'seo_decline');
        }

        $change = (($current - $previous) / $previous) * 100;

        return $change <= self::SEO_DECLINE_THRESHOLD
            ? $this->raise($site, 'seo_decline', 'Search clicks declining', sprintf('Search clicks are down %.0f%% vs the previous 7 days.', abs($change)), 'medium')
            : $this->clear($site, 'seo_decline');
    }

    private function performanceRegression(Site $site): ?Alert
    {
        $runs = PerformanceRun::where('site_id', $site->id)
            ->where('strategy', 'mobile')->where('status', 'complete')
            ->orderByDesc('run_at')->limit(2)->get(['performance_score']);

        if ($runs->count() < 2 || $runs[0]->performance_score === null || $runs[1]->performance_score === null) {
            return $this->clear($site, 'performance_regression');
        }

        $drop = $runs[1]->performance_score - $runs[0]->performance_score;

        return $drop >= self::PERFORMANCE_REGRESSION_POINTS
            ? $this->raise($site, 'performance_regression', 'Performance score dropped', "Mobile performance score fell {$drop} points vs the previous test.", 'medium')
            : $this->clear($site, 'performance_regression');
    }

    private function syncStale(Site $site): ?Alert
    {
        $lastFailed = SyncLog::where('site_id', $site->id)->where('status', 'failed')->latest('id')->first();
        if ($lastFailed && $lastFailed->created_at?->gt(now()->subHours(24))) {
            $this->clear($site, 'sync_stale');
            return $this->raise($site, 'sync_failed', 'A recent sync failed', $lastFailed->message ?: "The {$lastFailed->job} sync failed.", 'medium');
        }
        $this->clear($site, 'sync_failed');

        if (! $site->last_sync_at || $site->last_sync_at->lt(Carbon::now()->subHours(self::SYNC_STALE_HOURS))) {
            return $this->raise($site, 'sync_stale', 'Data sync is stale', 'This site hasn\'t synced in over ' . self::SYNC_STALE_HOURS . ' hours — check the plugin\'s connection status.', 'medium');
        }

        return $this->clear($site, 'sync_stale');
    }

    private function brokenLinkSpike(Site $site, DateRange $range): ?Alert
    {
        $spiking = BrokenLinkSnapshot::where('site_id', $site->id)
            ->where('status', 'open')
            ->where('last_seen_at', '>=', $range->startLocal)
            ->where('hit_count', '>=', self::BROKEN_LINK_SPIKE_HITS)
            ->count();

        return $spiking > 0
            ? $this->raise($site, 'broken_link_spike', '404 spike detected', "{$spiking} broken link(s) are getting repeated hits this week.", 'low')
            : $this->clear($site, 'broken_link_spike');
    }

    /** Create an alert of this type if none is currently open; otherwise leave the existing one as-is. */
    private function raise(Site $site, string $type, string $title, string $message, string $severity): ?Alert
    {
        $existing = Alert::where('site_id', $site->id)->where('type', $type)->where('is_resolved', false)->first();
        if ($existing) {
            return null; // already alerted — dedup
        }

        return Alert::create([
            'site_id'     => $site->id,
            'type'        => $type,
            'title'       => $title,
            'message'     => $message,
            'severity'    => $severity,
            'is_resolved' => false,
        ]);
    }

    /** Auto-resolve any open alert of this type — the condition that raised it is no longer true. */
    private function clear(Site $site, string $type): ?Alert
    {
        Alert::where('site_id', $site->id)->where('type', $type)->where('is_resolved', false)
            ->update(['is_resolved' => true]);

        return null;
    }
}
