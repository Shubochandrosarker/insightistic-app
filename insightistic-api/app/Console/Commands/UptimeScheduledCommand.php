<?php

namespace App\Console\Commands;

use App\Jobs\ProbeUptimeJob;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Illuminate\Console\Command;

/**
 * Scheduled uptime checks (Phase 12, doc 16).
 *
 * The routes/console.php entry runs every 5 minutes with
 * withoutOverlapping; the per-monitor "is it due?" decision happens HERE
 * so the schedule entry itself stays dumb and per-plan intervals work
 * without per-plan schedule entries: each active monitor carries its own
 * interval_minutes (plan-derived at monitor creation — roadmap 16:
 * Starter 10, Growth 5, Agency 1-5) and is dispatched only when its last
 * check is older than that interval.
 *
 * Unlike the crawler, no plan entitlement gates a monitor from existing —
 * the interval IS the plan throttle (high-frequency checks are recurring
 * infrastructure cost, doc 16), and it is enforced by this due-check, not
 * by the client.
 */
class UptimeScheduledCommand extends Command
{
    protected $signature = 'insightistic:uptime-check
                            {--site= : Limit to one site id}
                            {--dry-run : Report what would be dispatched}';

    protected $description = 'Queue uptime probes for monitors that are due on their interval';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $dispatched = 0;
        $skipped = 0;

        UptimeMonitor::query()
            ->where('active', true)
            ->when($this->option('site'), fn ($q) => $q->where('site_id', $this->option('site')))
            ->whereHas('site', fn ($q) => $q->whereNull('archived_at'))
            ->with('site.organization')
            ->chunkById(200, function ($monitors) use ($dry, &$dispatched, &$skipped) {
                foreach ($monitors as $monitor) {
                    $site = $monitor->site;
                    $org = $site?->organization;

                    if ($site === null
                        || ($org !== null && $org->deletion_scheduled_for !== null)
                        || ! $monitor->isDue()) {
                        $skipped++;
                        continue;
                    }

                    if ($dry) {
                        $this->line("would probe site {$monitor->site_id} every {$monitor->interval_minutes} min ({$monitor->probeUrl()})");
                        continue;
                    }

                    ProbeUptimeJob::dispatch($monitor->id);
                    $dispatched++;
                }
            });

        $this->info($dry
            ? 'Uptime checks: dry run complete.'
            : "Uptime checks: {$dispatched} dispatched, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
