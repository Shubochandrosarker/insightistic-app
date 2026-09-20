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
 * Privacy maintenance (spec Phase 10): enforce each plan's retention window,
 * expire export archives past their TTL, and complete organization deletions
 * whose grace window has elapsed. Runs at 03:30 — after the 02:00 snapshot
 * build, so a purge never races the job that reads the same tables.
 */
Schedule::command('insightistic:privacy-maintenance')
    ->dailyAt('03:30')
    ->withoutOverlapping();

/*
 * Direct data sources (GA4, Search Console, Cloudflare — spec Phase 3.2).
 * Runs before the 02:00 snapshot build so the night's snapshots are computed
 * over data that was just refreshed rather than yesterday's. Each source is
 * queued as its own job, so one provider being down can't stall the others.
 */
Schedule::command('insightistic:sync-data-sources')
    ->dailyAt('01:20')
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
 * PageSpeed Insights from the SaaS backend (spec Phase 3.3). Daily at 04:10,
 * ahead of the 6-hourly alert scan so AlertDetector's performance-regression
 * rule compares two genuinely recent runs rather than yesterday's pair.
 * Quota guards live in the job, not here.
 */
Schedule::command('insightistic:pagespeed')
    ->dailyAt('04:10')
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

/*
 * Proactive insight generation (spec Phase 6). Daily at 05:00 — last in the
 * nightly chain, so the AI reads freshly synced sources (01:20), rebuilt
 * snapshots (02:00) and the night's PageSpeed run (04:10) rather than
 * yesterday's numbers. Plan limits and freshness dedup live in the command.
 */
Schedule::command('insightistic:insights-generate')
    ->dailyAt('05:00')
    ->withoutOverlapping();

/*
 * Telemetry retention (spec Phase 2.3). 03:50 — after privacy maintenance
 * (03:30) so the two deletion passes never contend, and before the day's
 * traffic builds. Observability data that grows without bound eventually
 * becomes the performance problem it was installed to detect.
 */
Schedule::command('insightistic:telemetry-prune')
    ->dailyAt('03:50')
    ->withoutOverlapping();

/*
 * Automation Studio (spec: Insightistic VNext P6) schedule-triggered
 * rules. Hourly — the finest cadence a rule can configure
 * (trigger_config.frequency = 'hourly'); AutomationDispatcher's own
 * shouldFireSchedule() decides per-rule whether a daily/weekly rule is
 * actually due on any given run, so this can safely run more often than
 * every rule needs without double-firing anything.
 */
Schedule::command('insightistic:automation-run-scheduled')
    ->hourly()
    ->withoutOverlapping();

/*
 * Anomaly Engine (spec: Insightistic VNext P7). Every 6 hours, same
 * cadence as the alert scan it complements — the underlying data
 * (metric_snapshots/traffic_daily/seo_daily) is daily-granularity, so
 * more-frequent runs wouldn't surface anything new within a day.
 */
Schedule::command('insightistic:anomalies-scan')
    ->everySixHours()
    ->withoutOverlapping();

/*
 * Outcome Verification (spec: Insightistic VNext P11). Daily — a
 * verification isn't due until 24h after its baseline was captured
 * anyway (OutcomeVerificationService::MIN_HOURS_BEFORE_VERIFY), so a
 * finer cadence would just find nothing due most runs.
 */
Schedule::command('insightistic:outcome-verifications-run')
    ->dailyAt('06:00')
    ->withoutOverlapping();

/*
 * Billing reconciliation (roadmap 23-STRIPE-TO-PADDLE-MIGRATION §4.3).
 * Daily at 04:30 — after the nightly writes (snapshots 02:00, privacy
 * 03:30, telemetry 03:50) and before business hours, so any drift webhooks
 * missed is repaired before the day's first billing surface renders.
 * Runbook: docs/roadmap-2026/23-STRIPE-TO-PADDLE-MIGRATION.md. No-ops
 * gracefully while PADDLE_API_KEY is unset.
 */
Schedule::command('insightistic:billing-reconcile')
    ->dailyAt('04:30')
    ->withoutOverlapping();
