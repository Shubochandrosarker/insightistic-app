<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Rank\RankChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One daily rank check of one site (Phase 10, doc 13): the queue citizen
 * around RankChecker::checkSite — all mechanics (same-day suppression,
 * persistence, alerts) live in the orchestrator.
 *
 * tries=1 on purpose: a failed provider call is left visible in the failed
 * jobs/logs rather than silently retried — a retry after a partial success
 * would risk double-billing the provider for keywords already observed.
 * Same-day suppression makes accidental double-dispatch cheap instead.
 */
class RankCheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $siteId,
        public ?array $keywordIds = null,
    ) {
    }

    public function handle(RankChecker $checker): void
    {
        $site = Site::withoutGlobalScope('organization')->find($this->siteId);

        if ($site === null || $site->archived_at !== null) {
            return; // Site deleted or archived between queueing and running.
        }

        $checker->checkSite($site, $this->keywordIds);
    }
}
