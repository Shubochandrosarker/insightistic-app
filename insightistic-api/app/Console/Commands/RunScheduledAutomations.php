<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\Site;
use App\Services\Automation\AutomationDispatcher;
use Illuminate\Console\Command;

/** Fires every active 'schedule'-triggered AutomationRule whose cadence is due (spec: Automation Studio, P6). Run hourly; AutomationDispatcher::shouldFireSchedule() decides per-rule whether hourly/daily/weekly is actually due. */
class RunScheduledAutomations extends Command
{
    protected $signature = 'insightistic:automation-run-scheduled {--site= : Limit to one site id}';

    protected $description = 'Fire every due schedule-triggered Automation Studio rule.';

    public function handle(AutomationDispatcher $dispatcher): int
    {
        $sitesQuery = Site::query();
        if ($id = $this->option('site')) {
            $sitesQuery->whereKey($id);
        }
        $siteIds = $sitesQuery->pluck('id');

        $sitesWithScheduleRules = AutomationRule::query()
            ->whereIn('site_id', $siteIds)
            ->where('trigger_type', 'schedule')
            ->where('is_active', true)
            ->distinct()
            ->pluck('site_id');

        $sites = Site::query()->whereIn('id', $sitesWithScheduleRules)->get();

        // One dispatch() call per site: it evaluates every one of that
        // site's active schedule rules internally via
        // AutomationDispatcher::shouldFireSchedule(), the single source of
        // truth for "is this rule's cadence due right now" — this command
        // stays a thin driver, not a second copy of that logic.
        $total = 0;
        foreach ($sites as $site) {
            $total += count($dispatcher->dispatch('schedule', $site, []));
        }

        $this->info("Done — {$total} automation(s) fired.");

        return self::SUCCESS;
    }
}
