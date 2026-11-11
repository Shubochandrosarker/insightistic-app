<?php

namespace App\Console\Commands;

use App\Jobs\RankCheckJob;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Services\Rank\RankProvider;
use Illuminate\Console\Command;

/**
 * Scheduled rank checks (Phase 10, doc 13). The nightly 06:00 schedule
 * entry runs this command; the per-site "who has tracked keywords?"
 * decision happens here so the schedule entry itself stays dumb.
 *
 * Every active site with at least one active rank keyword gets one queued
 * RankCheckJob — the provider batch, same-day suppression and metering all
 * happen inside the job/orchestrator, so an overlapping or retried command
 * run never duplicates observations or double-bills. Same-day suppression
 * also means a re-run later in the day is a safe no-op.
 *
 * When the provider is not configured the command logs a warning and exits
 * SUCCESS: unconfigured credentials are an expected state (free plans,
 * fresh installs), not a failure — rank tracking simply costs nothing.
 */
class RankCheckScheduledCommand extends Command
{
    protected $signature = 'insightistic:rank-check
                            {--site= : Limit to one site id}';

    protected $description = 'Queue daily SERP rank checks for sites with active tracked keywords';

    public function handle(): int
    {
        $provider = app(RankProvider::class);

        if (! $provider->available()) {
            $this->warn(sprintf('Rank tracking provider "%s" is not configured (credentials missing) — skipping rank checks.', $provider->name()));

            return self::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;

        // Sites that actually track at least one active keyword (Site has no
        // rankKeywords relation — resolve through the keyword table).
        $siteIds = RankKeyword::query()
            ->where('active', true)
            ->distinct()
            ->pluck('site_id');

        Site::withoutGlobalScope('organization')
            ->when($this->option('site'), fn ($query) => $query->whereKey($this->option('site')))
            ->whereIn('id', $siteIds)
            ->whereNull('archived_at')
            ->with('organization')
            ->chunkById(100, function ($sites) use (&$dispatched, &$skipped) {
                foreach ($sites as $site) {
                    $org = $site->organization;

                    if (! $org || $org->deletion_scheduled_for !== null) {
                        $skipped++;
                        continue;
                    }

                    RankCheckJob::dispatch($site->id);
                    $dispatched++;
                }
            });

        $this->info("Rank checks: {$dispatched} dispatched, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
