<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\MetricSnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuilds the full snapshot history for one site. Dispatched after a sync
 * completes so the dashboard reflects freshly ingested orders. Carries only the
 * site id (queue-safe) and reloads the model in handle().
 */
class RebuildSiteSnapshots implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $siteId) {}

    public function handle(MetricSnapshotService $service): void
    {
        // No tenant context in a queued job: snapshots are scoped by site_id,
        // and Site's org global scope is a no-op when no tenant is bound.
        $site = Site::find($this->siteId);
        if ($site) {
            $service->rebuild($site);
        }
    }
}
