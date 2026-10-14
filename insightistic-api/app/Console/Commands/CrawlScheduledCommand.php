<?php

namespace App\Console\Commands;

use App\Jobs\CrawlSiteJob;
use App\Models\CrawlRun;
use App\Models\Site;
use App\Services\UsageService;
use Illuminate\Console\Command;

/**
 * Scheduled site crawls (Phase 7, doc 12).
 *
 * Every active site whose plan carries a crawl_pages_monthly entitlement
 * gets crawled on its plan's cadence: crawl_frequency_days = 1 (daily) on
 * the paid tiers, 7 (weekly) on free/starter. The nightly 03:00 schedule
 * entry runs this command; the per-site "is it due?" decision happens here
 * so the schedule entry itself stays dumb.
 *
 * Due means: the site's last finished run (completed OR failed — a site
 * whose last crawl errored still deserves a retry on cadence; trigger is
 * irrelevant, a manual run counts as the site's freshness mark) is older
 * than crawl_frequency_days, and nothing is currently in flight. The
 * monthly page budget itself is not spent here — CrawlController enforces
 * it for manual runs and each scheduled run is capped at
 * min(1000, plan limit), the same per-run ceiling the API applies.
 *
 * Unlike the PageSpeed scheduler, sites do NOT need a connected plugin —
 * the crawler fetches the live site over HTTP, so not-archived is the only
 * site filter. Plans without crawl_frequency_days default to weekly.
 */
class CrawlScheduledCommand extends Command
{
    protected $signature = 'insightistic:crawl-scheduled
                            {--site= : Limit to one site id}
                            {--dry-run : Report what would be dispatched}';

    protected $description = 'Queue scheduled crawls for sites that are due on their plan cadence';

    /** Per-run ceiling, mirroring CrawlController. */
    private const MAX_PAGES_PER_RUN = 1000;

    /** Cadence fallback when a plan predates crawl_frequency_days. */
    private const DEFAULT_FREQUENCY_DAYS = 7;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $dispatched = 0;
        $skipped = 0;

        Site::withoutGlobalScope('organization')
            ->when($this->option('site'), fn ($q) => $q->whereKey($this->option('site')))
            ->whereNull('archived_at')
            ->with('organization.plan')
            ->chunkById(100, function ($sites) use ($dry, &$dispatched, &$skipped) {
                foreach ($sites as $site) {
                    $org = $site->organization;

                    if (! $org || $org->deletion_scheduled_for !== null) {
                        $skipped++;
                        continue;
                    }

                    $usage = app(UsageService::class);
                    $limit = $usage->limit($org, 'http_crawl_pages_monthly');

                    if ($limit <= 0) {
                        $skipped++;
                        continue;
                    }

                    $frequency = max(1, (int) ($usage->limit($org, 'crawl_frequency_days') ?: self::DEFAULT_FREQUENCY_DAYS));

                    if ($this->hasRunInFlight($site->id) || ! $this->isDue($site->id, $frequency)) {
                        $skipped++;
                        continue;
                    }

                    if ($dry) {
                        $this->line("would dispatch a {$frequency}-day crawl (max {$limit} pages/mo) for site {$site->id}");
                        continue;
                    }

                    CrawlSiteJob::dispatch($site->id, min(self::MAX_PAGES_PER_RUN, $limit), 'schedule');
                    $dispatched++;
                }
            });

        $this->info($dry
            ? 'Scheduled crawls: dry run complete.'
            : "Scheduled crawls: {$dispatched} dispatched, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /** Anything pending or running means this site is already being crawled. */
    private function hasRunInFlight(int $siteId): bool
    {
        return CrawlRun::withoutGlobalScope('organization')
            ->where('site_id', $siteId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /** No finished run inside the cadence window — or none, ever. */
    private function isDue(int $siteId, int $frequencyDays): bool
    {
        $lastFinished = CrawlRun::withoutGlobalScope('organization')
            ->where('site_id', $siteId)
            ->whereIn('status', ['completed', 'failed'])
            ->orderByDesc('id')
            ->first();

        return $lastFinished === null
            || $lastFinished->created_at->lt(now()->subDays($frequencyDays));
    }
}
