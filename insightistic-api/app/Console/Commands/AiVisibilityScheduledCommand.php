<?php

namespace App\Console\Commands;

use App\Jobs\RunAiVisibilityJob;
use App\Models\AiVisibilityPrompt;
use App\Models\Site;
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

    public function handle(): int
    {
        $dispatched = 0;
        $skipped = 0;

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
            ->chunkById(100, function ($sites) use (&$dispatched, &$skipped) {
                foreach ($sites as $site) {
                    $org = $site->organization;

                    if (! $org || $org->deletion_scheduled_for !== null) {
                        $skipped++;
                        continue;
                    }

                    RunAiVisibilityJob::dispatch($site->id);
                    $dispatched++;
                }
            });

        $this->info("AI visibility runs: {$dispatched} dispatched, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
