<?php

namespace App\Console\Commands;

use App\Jobs\RunAiVisibilityJob;
use App\Models\AiVisibilityPrompt;
use App\Models\Site;
use App\Services\Ai\AiAvailability;
use App\Services\Observability\AiJobRunLog;
use Illuminate\Console\Command;

/**
 * Scheduled AI-visibility prompt runs (Phase 11, doc 14). The nightly 07:00
 * schedule entry runs this command; the per-site "does anyone monitor
 * prompts?" decision happens here so the schedule entry itself stays dumb.
 *
 * Every active site with at least one active AI-visibility prompt gets one
 * queued RunAiVisibilityJob — quota enforcement, cadence/due-picking and
 * per-prompt failure isolation all live inside the job, so an overlapping
 * or retried command run never duplicates observations or double-bills the
 * provider.
 */
class AiVisibilityScheduledCommand extends Command
{
    protected $signature = 'insightistic:ai-visibility
                            {--site= : Limit to one site id}';

    protected $description = 'Queue daily AI-visibility prompt runs for sites with active prompts';

    public function handle(AiAvailability $availability): int
    {
        $run = new AiJobRunLog('ai-visibility');

        // Sites that actually monitor at least one active prompt (Site has
        // no aiVisibilityPrompts relation — resolve through the prompt table).
        $siteIds = AiVisibilityPrompt::query()
            ->where('active', true)
            ->distinct()
            ->pluck('site_id');

        Site::withoutGlobalScope('organization')
            ->when($this->option('site'), fn ($query) => $query->whereKey($this->option('site')))
            ->whereIn('id', $siteIds)
            ->whereNull('archived_at')
            ->with('organization')
            ->chunkById(100, function ($sites) use ($availability, $run) {
                foreach ($sites as $site) {
                    $org = $site->organization;

                    if (! $org) {
                        $run->skip($site->id, 'no_organization');
                        continue;
                    }

                    if ($org->deletion_scheduled_for !== null) {
                        $run->skip($site->id, 'organization_deletion_scheduled', ['org_id' => $org->id]);
                        continue;
                    }

                    if ($availability->isPaused($org, $site)) {
                        $run->skip($site->id, 'ai_paused', ['org_id' => $org->id]);
                        continue;
                    }

                    $run->eligible();
                    RunAiVisibilityJob::dispatch($site->id);
                    $run->wrote($site->id);
                }
            });

        // "written" here means jobs queued; the watchdog checks the rows the
        // jobs actually wrote (ai_visibility_runs) later in the morning.
        $summary = $run->finish();

        $this->info("AI visibility runs: {$summary['written']} dispatched, {$summary['skipped']} skipped.");
        if (! empty($summary['reasons'])) {
            $this->line('Skip reasons: ' . json_encode($summary['reasons']));
        }

        return self::SUCCESS;
    }
}
