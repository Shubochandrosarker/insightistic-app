<?php

namespace App\Jobs;

use App\Models\CrawlRun;
use App\Models\Site;
use App\Services\Crawler\CrawlRunExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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
        public ?int $runId = null,
    ) {
    }

    public function handle(CrawlRunExecutor $executor): void
    {
        $site = Site::withoutGlobalScope('organization')->find($this->siteId);

        if ($site === null) {
            return; // Site deleted between queueing and running.
        }

        $run = $this->runId === null
            ? null
            : CrawlRun::query()
                ->where('site_id', $site->id)
                ->where('status', 'pending')
                ->find($this->runId);

        // A reserved manual run must still be pending when its worker starts.
        if ($this->runId !== null && $run === null) {
            return;
        }

        // Never stack crawls. Scheduled and initial runs do not reserve a row
        // until they execute; a queued manual run owns its own pending row.
        $otherInFlight = CrawlRun::query()
            ->where('site_id', $site->id)
            ->whereIn('status', ['pending', 'running'])
            ->when($this->runId !== null, fn ($query) => $query->where('id', '!=', $this->runId))
            ->exists();

        if ($otherInFlight) {
            $run?->update([
                'status' => 'failed',
                'max_pages' => 0,
                'error_summary' => 'Another crawl is already in progress.',
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            $executor->startRun($site, $this->maxPages, $this->trigger, $this->delayMs, $run?->id);
        } catch (Throwable $e) {
            // If startup fails before the executor can transition the row,
            // release its reserved budget while preserving the failure.
            if ($run?->fresh()?->status === 'pending') {
                $run->update([
                    'status' => 'failed',
                    'max_pages' => 0,
                    'error_summary' => 'The crawl could not start.',
                    'completed_at' => now(),
                ]);
            }

            throw $e;
        }
    }
}
