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

/*
 * Deterministic alert rules (spec §12) — revenue/traffic/SEO changes,
 * performance regressions, low stock, stale/failed syncs, 404 spikes. Every
 * 6 hours: most rules compare 7-day windows, so more-frequent runs wouldn't
 * catch anything new, just waste a run.
 */
Schedule::command('insightistic:alerts-scan')
    ->everySixHours()
    ->withoutOverlapping();

/*
 * Scheduled report delivery (spec §12). Hourly so send_time is matched to
 * within an hour of what the owner configured; dedup against
 * client_reports.sent_at means a missed/retried run never double-sends.
 */
Schedule::command('insightistic:reports-deliver')
    ->hourly()
    ->withoutOverlapping();
