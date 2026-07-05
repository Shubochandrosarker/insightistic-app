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

/*
 * Trial lifecycle: reminders (T-2, T-0), expiry enforcement (locks advanced
 * features, keeps free plugin working), and the day-7 win-back. Hourly; all
 * sends dedupe through email_logs so re-runs never double-send.
 */
Schedule::command('insightistic:trials')
    ->hourly()
    ->withoutOverlapping();
