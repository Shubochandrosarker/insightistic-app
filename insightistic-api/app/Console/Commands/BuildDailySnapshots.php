<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\MetricSnapshotService;
use Illuminate\Console\Command;

class BuildDailySnapshots extends Command
{
    protected $signature = 'insightistic:snapshots
        {--site= : Limit to one site id}
        {--backfill : Rebuild full history instead of recent days}
        {--date= : Build a single specific local date (Y-m-d)}';

    protected $description = 'Compute Insightistic metric snapshots (nightly incremental by default).';

    public function handle(MetricSnapshotService $service): int
    {
        $query = Site::query();
        if ($id = $this->option('site')) {
            $query->whereKey($id);
        }

        $sites = $query->get();
        if ($sites->isEmpty()) {
            $this->warn('No sites found.');
            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            if ($date = $this->option('date')) {
                $service->build($site, $date);
                $this->info("Site {$site->id}: built {$date}");
            } elseif ($this->option('backfill')) {
                $days = $service->rebuild($site);
                $this->info("Site {$site->id}: backfilled {$days} day(s)");
            } else {
                $service->buildRecent($site);
                $this->info("Site {$site->id}: rebuilt today + yesterday");
            }
        }

        return self::SUCCESS;
    }
}
