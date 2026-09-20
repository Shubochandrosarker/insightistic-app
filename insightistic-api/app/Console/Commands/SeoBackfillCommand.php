<?php

namespace App\Console\Commands;

use App\Jobs\BackfillGscHistory;
use App\Models\DataSourceCredential;
use App\Models\SeoBackfillState;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Owner-triggered historical Search Console backfill (Phase 5 search
 * intelligence). Deliberately NOT scheduled: the nightly sync keeps the
 * daily grain fresh, this only fills the past on demand.
 */
class SeoBackfillCommand extends Command
{
    protected $signature = 'insightistic:seo-backfill {--site=} {--days=180} {--dimension=queries}';

    protected $description = 'Queue a historical Search Console backfill into the daily-grain SEO tables.';

    /** GSC only exposes ~16 months in its UI; asking older just burns quota. */
    private const MAX_DAYS = 480;

    public function handle(): int
    {
        $dimension = (string) $this->option('dimension');
        if (! in_array($dimension, SeoBackfillState::DIMENSIONS, true)) {
            $this->error("Unknown dimension '{$dimension}' (queries|pages|query_page).");

            return self::FAILURE;
        }

        $siteKey = trim((string) $this->option('site'));
        if ($siteKey === '') {
            $this->error('Pass --site=<site-id-or-domain>.');

            return self::FAILURE;
        }

        $site = Site::withoutGlobalScope('organization')
            ->where(is_numeric($siteKey) ? 'id' : 'domain', $siteKey)
            ->first();

        if (! $site) {
            $this->error("Site '{$siteKey}' not found.");

            return self::FAILURE;
        }

        $credential = DataSourceCredential::withoutGlobalScope('organization')
            ->where('site_id', $site->id)
            ->where('source', 'gsc')
            ->first();

        if (! $credential || empty($credential->resource_json['id'])) {
            $this->error("Site #{$site->id} has no connected Search Console property. Connect one first.");

            return self::FAILURE;
        }

        $days = min(max((int) $this->option('days'), 1), self::MAX_DAYS);

        // Calendar dates follow the site's own timezone; GSC data lags ~3
        // days, so the window ends where the nightly sync's window ends.
        $tz = $site->timezone ?: config('app.timezone', 'UTC');
        $to = today($tz)->subDays(3)->toDateString();
        $from = today($tz)->subDays(3 + $days)->toDateString();

        $state = SeoBackfillState::firstOrCreate(
            ['site_id' => $site->id, 'dimension' => $dimension],
            ['status' => 'pending'],
        );

        BackfillGscHistory::dispatch($site, $dimension, $from, $to);

        $this->info("Backfill queued: site #{$site->id} {$dimension} {$from} → {$to} (state #{$state->id}, currently {$state->status}).");

        return self::SUCCESS;
    }
}
