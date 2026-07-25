<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Integrations\IntegrationFailure;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pull one direct data source for one site. Used by both the manual
 * "Sync now" button and the nightly scheduled run.
 *
 * Failures are already recorded on `data_source_connections` by
 * IntegrationManager, so the job only decides whether to retry: rate limits
 * and provider 5xx are worth another attempt, a revoked grant is not.
 */
class SyncDataSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Exponential backoff, in seconds, between the three attempts. */
    public array $backoff = [60, 300];

    public function __construct(
        public int $siteId,
        public string $source,
        public int $days = 30,
    ) {
    }

    /** One in-flight sync per (site, source); a queued duplicate is dropped. */
    public function uniqueId(): string
    {
        return "sync-data-source:{$this->siteId}:{$this->source}";
    }

    public function handle(IntegrationManager $manager): void
    {
        $site = Site::withoutGlobalScope('organization')->find($this->siteId);

        if (! $site) {
            return; // Site deleted between queueing and running.
        }

        try {
            $manager->sync($site, $this->source, $this->days);
        } catch (IntegrationFailure $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }

            // Terminal: the state row already carries the error for the UI.
            // Don't fail the job — that would just noise up failed_jobs for a
            // condition only the user can fix by reconnecting.
            logger()->warning('Data source sync failed terminally.', [
                'site_id' => $this->siteId,
                'source'  => $this->source,
                'code'    => $e->errorCode,
            ]);
        }
    }
}
