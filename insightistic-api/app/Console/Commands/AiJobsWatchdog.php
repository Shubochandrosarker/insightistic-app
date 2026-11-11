<?php

namespace App\Console\Commands;

use App\Models\AiInsight;
use App\Models\AiVisibilityPrompt;
use App\Models\AiVisibilityRun;
use App\Models\Site;
use App\Services\Ai\AiAvailability;
use App\Services\Observability\AiJobAlert;
use App\Services\Observability\AiJobRunLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Zero-rows alarm for the daily AI jobs.
 *
 * Nov 2026 incident: insights-generate and ai-visibility crashed every
 * morning for five days (an undefined variable in a hotfix) while the
 * scheduler printed "DONE" and sent output to /dev/null. Nothing measured
 * the thing that matters — rows written. This command does: it counts
 * ai_insights and ai_visibility_runs written inside the window and alerts
 * when there were eligible sites but zero rows.
 */
class AiJobsWatchdog extends Command
{
    public const CACHE_KEY = 'ai_jobs:watchdog';

    protected $signature = 'insightistic:ai-jobs-watchdog
                            {--hours=24 : Look-back window}';

    protected $description = 'Alert when a daily AI job wrote zero rows for eligible sites';

    public function handle(AiAvailability $availability, AiJobAlert $alert): int
    {
        $since = now()->subHours(max(1, (int) $this->option('hours')));

        $connected = Site::withoutGlobalScope('organization')
            ->whereNull('archived_at')
            ->where('connection_status', 'connected')
            ->with('organization')
            ->get();

        $promptSiteIds = AiVisibilityPrompt::query()->where('active', true)->distinct()->pluck('site_id');
        $promptSites = Site::withoutGlobalScope('organization')
            ->whereNull('archived_at')
            ->whereIn('id', $promptSiteIds)
            ->with('organization')
            ->get();

        $eligible = fn ($sites) => $sites->filter(fn (Site $s) => $s->organization
            && $s->organization->deletion_scheduled_for === null
            && ! $availability->isPaused($s->organization, $s))->count();

        $checks = [
            'insights-generate' => [
                'eligible_sites' => $eligible($connected),
                'rows'           => AiInsight::withoutGlobalScope('organization')->where('created_at', '>=', $since)->count(),
                'table'          => 'ai_insights',
            ],
            'ai-visibility' => [
                'eligible_sites' => $eligible($promptSites),
                'rows'           => AiVisibilityRun::query()->where('created_at', '>=', $since)->count(),
                'table'          => 'ai_visibility_runs',
            ],
        ];

        $failed = false;
        foreach ($checks as $job => &$check) {
            $check['last_run'] = Cache::get(AiJobRunLog::cacheKey($job));
            $check['ok'] = ! ($check['eligible_sites'] > 0 && $check['rows'] === 0);

            $this->line(sprintf('%s: %d eligible sites, %d %s rows since %s — %s',
                $job, $check['eligible_sites'], $check['rows'], $check['table'], $since->toDateTimeString(), $check['ok'] ? 'OK' : 'ZERO ROWS'));

            if (! $check['ok']) {
                $failed = true;
                $alert->notify('zero_rows:' . $job, "{$job} wrote 0 {$check['table']} rows in the last {$this->option('hours')}h", [
                    'eligible_sites' => $check['eligible_sites'],
                    'since'          => $since->toIso8601String(),
                    'last_run'       => $check['last_run'] ?? 'no summary recorded (command crashed or never ran)',
                ]);
            }
        }
        unset($check);

        try {
            Cache::forever(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'checks' => $checks]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
