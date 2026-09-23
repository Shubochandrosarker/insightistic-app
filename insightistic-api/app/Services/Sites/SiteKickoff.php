<?php

namespace App\Services\Sites;

use App\Jobs\CrawlSiteJob;
use App\Jobs\DetectPlatformJob;
use App\Jobs\ProbeUptimeJob;
use App\Jobs\RunPageSpeedTest;
use App\Models\Site;
use App\Models\UptimeMonitor;

/**
 * What happens the moment a site exists (2026-09-23 milestone): the user
 * must see useful work starting within seconds, without installing any
 * plugin — an initial crawl, an uptime monitor (with its first probe), a
 * PageSpeed run, and platform detection.
 *
 * Every step is best-effort and queued: a failure in one (e.g. no
 * PageSpeed key configured) must never block site creation itself.
 */
class SiteKickoff
{
    /** @return string[] the kickoff steps that were queued/created */
    public function start(Site $site): array
    {
        $started = [];

        $started[] = 'platform_detect';
        DetectPlatformJob::dispatch($site->id);

        $maxPages = (int) ($site->organization?->plan?->limit('crawl_max_pages') ?: 50);
        $started[] = 'crawler';
        CrawlSiteJob::dispatch($site->id, $maxPages, 'initial');

        // One default monitor per site: GET the canonical URL. The plan's
        // interval floor is applied by UptimeController on user edits; the
        // default here starts conservative.
        $url = $site->canonical_url ?: ('https://' . $site->normalized_domain);
        $monitor = UptimeMonitor::query()->firstOrCreate(
            ['site_id' => $site->id],
            [
                'url'              => $url,
                'method'           => 'GET',
                'expected_status'  => 200,
                'interval_minutes' => 5,
                'active'           => true,
            ],
        );
        $started[] = 'uptime';
        ProbeUptimeJob::dispatch($monitor->id);

        if (config('insightistic.pagespeed.api_key')) {
            $started[] = 'pagespeed';
            RunPageSpeedTest::dispatch($site->id, 'mobile', $url);
        }

        return $started;
    }
}
