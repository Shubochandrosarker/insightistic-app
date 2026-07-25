<?php

namespace App\Console\Commands;

use App\Jobs\RunPageSpeedTest;
use App\Models\Site;
use App\Services\Performance\PageSpeedClient;
use Illuminate\Console\Command;

/**
 * Queue scheduled PageSpeed runs for connected sites (spec Phase 3.3).
 *
 * Runs before the 6-hourly alert scan, so `AlertDetector`'s
 * performance-regression rule compares two genuinely recent runs rather
 * than yesterday's pair.
 *
 * Only connected, non-archived sites are queued: a pending or disconnected
 * site would burn shared API quota producing a run nobody is looking at.
 */
class RunPageSpeedTests extends Command
{
    protected $signature = 'insightistic:pagespeed
                            {--site= : Limit to one site id}
                            {--strategy= : mobile or desktop (default: both)}';

    protected $description = 'Queue PageSpeed Insights runs for connected sites';

    public function handle(): int
    {
        $strategies = $this->option('strategy')
            ? [$this->option('strategy')]
            : PageSpeedClient::STRATEGIES;

        foreach ($strategies as $strategy) {
            if (! in_array($strategy, PageSpeedClient::STRATEGIES, true)) {
                $this->error("Unknown strategy: {$strategy}");

                return self::FAILURE;
            }
        }

        $queued = 0;

        Site::withoutGlobalScope('organization')
            ->when($this->option('site'), fn ($q) => $q->whereKey($this->option('site')))
            ->whereNull('archived_at')
            ->where('connection_status', 'connected')
            ->chunkById(100, function ($sites) use ($strategies, &$queued) {
                foreach ($sites as $site) {
                    foreach ($strategies as $strategy) {
                        RunPageSpeedTest::dispatch($site->id, $strategy);
                        $queued++;
                    }
                }
            });

        $this->info("Queued {$queued} PageSpeed run(s).");

        return self::SUCCESS;
    }
}
