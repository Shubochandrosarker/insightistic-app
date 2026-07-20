<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\AlertDetector;
use Illuminate\Console\Command;

class ScanAlerts extends Command
{
    protected $signature = 'insightistic:alerts-scan {--site= : Limit to one site id}';

    protected $description = 'Run the deterministic alert rules (spec §12) for every connected site.';

    public function handle(AlertDetector $detector): int
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
            $created = $detector->scan($site);
            $total += count($created);
            if ($created) {
                $this->info("Site {$site->id}: {$this->pluralize(count($created))} raised");
            }
        }

        $this->info("Done — {$this->pluralize($total)} raised across {$sites->count()} site(s).");

        return self::SUCCESS;
    }

    private function pluralize(int $count): string
    {
        return $count === 1 ? '1 alert' : "{$count} alerts";
    }
}
