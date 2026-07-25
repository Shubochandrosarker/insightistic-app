<?php

namespace App\Console\Commands;

use App\Jobs\SyncDataSource;
use App\Models\DataSourceCredential;
use App\Models\Site;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Console\Command;

/**
 * Queue a scheduled pull for every directly-connected data source
 * (spec Phase 3.2). Runs nightly from routes/console.php.
 *
 * Only sites with a usable credential *and* a chosen property are queued, so
 * a half-finished setup never generates nightly failures the user can't act
 * on — the Integrations page already tells them to pick a property.
 */
class SyncDataSources extends Command
{
    protected $signature = 'insightistic:sync-data-sources
                            {--site= : Limit to one site id}
                            {--source= : Limit to one source (ga4|gsc|cloudflare)}
                            {--days=30 : Days of history to pull}';

    protected $description = 'Queue GA4 / Search Console / Cloudflare syncs for connected sites';

    public function handle(IntegrationManager $manager): int
    {
        $sources = $this->option('source')
            ? [$this->option('source')]
            : DataSourceCredential::DIRECT_SOURCES;

        foreach ($sources as $source) {
            if (! in_array($source, DataSourceCredential::DIRECT_SOURCES, true)) {
                $this->error("Unknown source: {$source}");

                return self::FAILURE;
            }
        }

        $days = max(1, min(365, (int) $this->option('days')));
        $queued = 0;

        Site::withoutGlobalScope('organization')
            ->when($this->option('site'), fn ($q) => $q->whereKey($this->option('site')))
            ->whereNull('archived_at')
            ->chunkById(100, function ($sites) use ($manager, $sources, $days, &$queued) {
                foreach ($sites as $site) {
                    foreach ($sources as $source) {
                        if (! $manager->isConnected($site, $source)) {
                            continue;
                        }

                        SyncDataSource::dispatch($site->id, $source, $days);
                        $queued++;
                    }
                }
            });

        $this->info("Queued {$queued} data-source sync(s).");

        return self::SUCCESS;
    }
}
