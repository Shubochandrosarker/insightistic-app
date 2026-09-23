<?php

namespace App\Services\Sites;

use App\Models\CrawlRun;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Services\Crawler\CrawlHealthScore;

/**
 * The REAL site health shown on Overview (spec §19): a composite of crawl
 * issues (the dominant signal), SSL state and uptime availability — never
 * the WordPress connector status.
 *
 * Score composition (documented on purpose):
 *   base  = CrawlHealthScore (0-100, severity-weighted, category-capped)
 *   SSL   : cert expiring < 7 days  → -10 ; < 30 days → -4 ; expired → -15
 *   uptime: 30d availability < 95%  → -8  ; < 99%     → -3
 * A site with no audit yet reports state 'no_audit' and score null — an
 * honest "we don't know yet", never a fake green.
 */
class SiteHealthService
{
    public function __construct(private readonly CrawlHealthScore $crawlScore) {}

    /** @return array{state:string,score:?int,components:array<string,mixed>,sources:array<string,string>} */
    public function composite(Site $site): array
    {
        $crawl = $this->crawlScore->forSite($site);
        $baseScore = $crawl['score'] ?? null;

        $monitor = UptimeMonitor::query()->where('site_id', $site->id)->first();
        $sslDays = $monitor?->ssl_expires_at ? now()->diffInDays($monitor->ssl_expires_at, false) : null;
        $availability = $monitor?->availabilityPct();

        $sslPenalty = 0;
        if ($sslDays !== null) {
            $sslPenalty = $sslDays < 0 ? 15 : ($sslDays < 7 ? 10 : ($sslDays < 30 ? 4 : 0));
        }

        $uptimePenalty = 0;
        if ($availability !== null && $availability > 0) {
            $uptimePenalty = $availability < 95 ? 8 : ($availability < 99 ? 3 : 0);
        }

        $lastAudit = CrawlRun::query()
            ->where('site_id', $site->id)
            ->where('status', 'completed')
            ->latest('id')
            ->first(['id', 'completed_at', 'created_at']);

        if ($baseScore === null || ! $lastAudit) {
            return [
                'state' => 'no_audit',
                'score' => null,
                'components' => [
                    'crawl' => ['available' => false, 'note' => 'Run a site audit to compute health.'],
                    'ssl' => $this->sslComponent($sslDays),
                    'uptime' => $this->uptimeComponent($availability, $monitor),
                ],
                'sources' => $this->sources($site, $monitor, $lastAudit),
            ];
        }

        $score = max(0, min(100, (int) round($baseScore - $sslPenalty - $uptimePenalty)));

        return [
            'state' => $score >= 80 ? 'ok' : ($score >= 60 ? 'warning' : 'critical'),
            'score' => $score,
            'components' => [
                'crawl' => [
                    'available' => true,
                    'score' => $baseScore,
                    'open_issues' => array_sum(array_column($crawl['breakdown'] ?? [], 'open_issues')),
                    'breakdown' => $crawl['breakdown'] ?? [],
                    'mode' => $crawl['mode'] ?? 'uniform',
                    'top_issues' => $crawl['top_issues'] ?? [],
                    'last_audit_at' => $lastAudit->completed_at?->toIso8601String() ?? $lastAudit->created_at?->toIso8601String(),
                ],
                'ssl' => $this->sslComponent($sslDays),
                'uptime' => $this->uptimeComponent($availability, $monitor),
            ],
            'sources' => $this->sources($site, $monitor, $lastAudit),
        ];
    }

    private function sslComponent(?float $days): array
    {
        if ($days === null) {
            return ['available' => false, 'state' => 'unknown', 'note' => 'SSL checked by the uptime monitor.'];
        }

        return [
            'available' => true,
            'state' => $days < 0 ? 'expired' : ($days < 30 ? 'expiring' : 'ok'),
            'expires_in_days' => (int) max(0, $days),
        ];
    }

    private function uptimeComponent(null|float|int $availability, ?UptimeMonitor $monitor): array
    {
        return [
            'available' => $monitor !== null,
            'active' => (bool) $monitor?->active,
            'availability' => $availability !== null ? round((float) $availability, 3) : null,
            'interval_minutes' => $monitor?->interval_minutes,
        ];
    }

    /** Which system produced each signal — Overview shows "source: site audit". */
    private function sources(Site $site, ?UptimeMonitor $monitor, ?CrawlRun $lastAudit): array
    {
        return [
            'health' => $lastAudit ? 'site_audit' : 'none',
            'ssl' => $monitor?->ssl_checked_at ? 'uptime_monitor' : 'none',
            'uptime' => $monitor?->last_checked_at ? 'uptime_monitor' : 'none',
        ];
    }
}
