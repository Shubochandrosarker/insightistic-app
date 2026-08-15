<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Anomaly\AnomalyScanner;
use Illuminate\Console\Command;

class ScanAnomalies extends Command
{
    protected $signature = 'insightistic:anomalies-scan {--site= : Limit to one site id}';

    protected $description = 'Run the median/MAD anomaly detector (spec: Anomaly Engine, P7) for every connected site.';

    public function handle(AnomalyScanner $scanner): int
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

        $total = 0;
        foreach ($sites as $site) {
            $created = $scanner->scan($site);
            $total += count($created);
            if ($created) {
                $this->info("Site {$site->id}: ".count($created)." anomal".(count($created) === 1 ? 'y' : 'ies').' found');
            }
        }

        $this->info("Done — {$total} anomal".($total === 1 ? 'y' : 'ies')." found across {$sites->count()} site(s).");

        return self::SUCCESS;
    }
}
