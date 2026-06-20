<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Nightly incremental snapshot build for every connected site.
 * Backfill is handled separately (after sync, or `insightistic:snapshots --backfill`).
 * Requires the system cron entry:
 *   * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::command('insightistic:snapshots')
    ->dailyAt('02:00')
    ->withoutOverlapping();
