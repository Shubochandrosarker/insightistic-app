<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Services\Uptime\IncidentManager;
use App\Services\Uptime\Probe;
use App\Services\Uptime\SslInspector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * One uptime probe of one monitor (Phase 12, doc 16): the queue citizen
 * around Probe -> IncidentManager::record, stamping last_checked_at and
 * piggybacking the daily SSL inspection (SslInspector self-gates to 24h,
 * so the job can call it unconditionally — the gate lives there, but we
 * skip the method call cheaply here to keep hot intervals clean).
 *
 * tries=1 like RankCheckJob: a crashed probe must not be transparently
 * retried into a SECOND failure observation — the two-probe confirmation
 * rule depends on each recorded check being a genuinely separate probe.
 * The next scheduler pass (<= 5 min later) is the retry.
 */
class ProbeUptimeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $monitorId,
    ) {
    }

    public function handle(Probe $probe, IncidentManager $incidents, SslInspector $ssl): void
    {
        $monitor = UptimeMonitor::query()->find($this->monitorId);

        if ($monitor === null || ! $monitor->active) {
            return; // Monitor deleted or deactivated between queueing and running.
        }

        $site = $monitor->site;

        if (! $site instanceof Site
            || $site->archived_at !== null
            || $site->organization?->deletion_scheduled_for !== null) {
            return; // Site gone/archived/deletion-pending since dispatch.
        }

        $incidents->record($monitor, $probe->probe($monitor));

        $monitor->last_checked_at = Carbon::now();
        $monitor->save();

        if ($monitor->ssl_checked_at === null || $monitor->ssl_checked_at->lt(Carbon::now()->subDay())) {
            $ssl->check($monitor);
        }
    }
}
