<?php

namespace App\Jobs;

use App\Models\CrawlRun;
use App\Services\Crawler\CrawlRunExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One hop of a crawl chain: claims the next frontier URL, processes it, and
 * (if the executor says the chain continues) dispatches its own successor.
 * This self-perpetuating shape means N queue workers drain a run's frontier
 * in parallel without any central scheduler, and the run's page budget is
 * enforced by the executor's atomic frontier claims.
 *
 * tries = 1 on purpose: a failed URL is crawl data (a 500 page, a timeout),
 * not a job failure. The executor isolates per-URL errors and the chain
 * moves on.
 */
class CrawlUrlJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $runId)
    {
    }

    public function handle(CrawlRunExecutor $executor): void
    {
        $run = CrawlRun::query()->find($this->runId);

        if ($run === null || $run->status !== 'running') {
            return; // cancelled, completed or deleted between dispatches.
        }

        if ($executor->processNext($run) === 'continue') {
            dispatch(new self($this->runId));
        }
    }
}
