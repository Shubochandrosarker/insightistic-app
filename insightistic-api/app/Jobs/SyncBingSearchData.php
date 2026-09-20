<?php

namespace App\Jobs;

use App\Models\DataSourceCredential;
use App\Services\Integrations\IntegrationFailure;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly Bing Webmaster pull (roadmap 11-BING-WEBMASTER-INDEXNOW).
 *
 * Bing returns its full available history on every call (no date-range
 * parameter), so "sync" is the same as "repair": replaying refreshes every
 * daily/weekly bucket in place via upserts. The job sweeps every site with a
 * usable Bing credential — or just one when $siteId is set — and funnels
 * each site through IntegrationManager::sync('bing') so connection state,
 * freshness and error codes are recorded exactly like the other sources.
 *
 * Scheduling: picked up automatically by the existing nightly
 * `insightistic:sync-data-sources` command (it iterates
 * DataSourceCredential::DIRECT_SOURCES, which now includes 'bing'), so no
 * extra routes/console.php entry is required.
 */
class SyncBingSearchData implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Slower backoff than the default sync — Bing data lags days, not minutes. */
    public array $backoff = [300, 900];

    /** Hard ceiling on rows written per run, so a runaway account can't stall the queue. */
    private const MAX_ROWS_PER_RUN = 5000;

    public function __construct(public ?int $siteId = null)
    {
    }

    public function uniqueId(): string
    {
        return 'sync-bing-search-data:' . ($this->siteId ?? 'all');
    }

    public function handle(IntegrationManager $manager): void
    {
        $credentials = DataSourceCredential::withoutGlobalScope('organization')
            ->where('source', 'bing')
            ->when($this->siteId, fn ($q) => $q->where('site_id', $this->siteId))
            ->with('site')
            ->get();

        $written = 0;

        foreach ($credentials as $credential) {
            // Half-finished connects (no mapped Bing site yet) are skipped,
            // not failed — the Integrations page already asks for the mapping.
            $site = $credential->site;

            if (! $site || $site->archived_at || empty($credential->resource_json['id'])) {
                continue;
            }

            if ($written >= self::MAX_ROWS_PER_RUN) {
                return; // Safety cap: pick the rest up on the next nightly run.
            }

            try {
                $result = $manager->sync($site, 'bing');
            } catch (IntegrationFailure $e) {
                if ($e->retryable && $this->attempts() < $this->tries) {
                    $this->release($this->backoff[$this->attempts() - 1] ?? 900);

                    return;
                }

                // Terminal: IntegrationManager already wrote the error onto
                // data_source_connections for the UI; failing the job would
                // only noise up failed_jobs for a user-fixable condition.
                logger()->warning('Bing search data sync failed terminally.', [
                    'site_id' => $credential->site_id,
                    'code'    => $e->errorCode,
                ]);

                continue;
            }

            $written += (int) ($result['days'] ?? 0) + (int) ($result['queries'] ?? 0);
        }
    }
}
