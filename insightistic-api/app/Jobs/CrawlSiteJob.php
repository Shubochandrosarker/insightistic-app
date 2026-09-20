<?php

namespace App\Jobs;

use App\Models\CrawlRun;
use App\Models\Site;
use App\Services\Crawler\CrawlRunExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kick off one crawl of one site: creates the run, seeds the frontier
 * (homepage + sitemap discovery) and dispatches the initial CrawlUrlJob
 * chains. Used by the manual "crawl now" action and the plan schedule.
 *
 * The job is the queue citizen; all mechanics live in CrawlRunExecutor.
 */
class CrawlSiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** One crawl at a time per site; a queued duplicate is dropped. */
    public function uniqueId(): string
    {
        return "crawl-site:{$this->siteId}";
    }

    public function __construct(
        public int $siteId,
        public int $maxPages = 1000,
        public string $trigger = 'manual',
        public int $delayMs = CrawlRunExecutor::DEFAULT_DELAY_MS,
    ) {
    }

    public function handle(CrawlRunExecutor $executor): void
    {
        $site = Site::withoutGlobalScope('organization')->find($this->siteId);

        if ($site === null) {
            return; // Site deleted between queueing and running.
        }

        // Never stack crawls: a still-running run for this site wins.
        if (CrawlRun::query()->where('site_id', $site->id)->where('status', 'running')->exists()) {
            return;
        }

        $executor->startRun($site, $this->maxPages, $this->trigger, $this->delayMs);
    }
}
