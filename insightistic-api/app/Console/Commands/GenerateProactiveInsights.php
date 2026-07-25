<?php

namespace App\Console\Commands;

use App\Models\AiInsight;
use App\Models\Organization;
use App\Models\Site;
use App\Services\ContextAssembler;
use App\Services\InsightGenerator;
use App\Services\UsageService;
use App\Support\DateRange;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Proactive insight generation (spec Phase 6).
 *
 * Insights previously only existed when someone pressed the button, which
 * meant the feature that is supposed to tell you something changed only
 * spoke when you already suspected it had.
 *
 * Three guards keep this from being expensive or noisy:
 *
 *  - **Plan limits.** Checked per organization before every generation, and
 *    the loop abandons that org as soon as its allowance is gone. Proactive
 *    generation must never eat the quota a user wants for their own
 *    questions, so it stops at the limit rather than borrowing against it.
 *  - **Deduplication.** A type is skipped when a live insight of that type
 *    already exists inside the freshness window. Regenerating the same
 *    finding every night trains people to ignore the list.
 *  - **Evidence first.** Context is assembled before metering, so a site
 *    with nothing to say costs no quota — the same rule the interactive
 *    endpoint follows.
 */
class GenerateProactiveInsights extends Command
{
    protected $signature = 'insightistic:insights-generate
                            {--site= : Limit to one site id}
                            {--type=* : Limit to specific contextual types}
                            {--dry-run : Report what would be generated}';

    protected $description = 'Generate scheduled contextual insights for connected sites';

    /**
     * The types worth producing unprompted. Deliberately not all ten:
     * `report` is a user-initiated summary, and generating every type nightly
     * would bury the ones that matter.
     */
    private const PROACTIVE_TYPES = ['executive', 'traffic', 'seo', 'commerce', 'alerts'];

    public function handle(
        ContextAssembler $contexts,
        InsightGenerator $generator,
        UsageService $usage,
        Tenancy $tenancy,
    ): int {
        $types = $this->option('type') ?: self::PROACTIVE_TYPES;

        foreach ($types as $type) {
            if (! in_array($type, ContextAssembler::TYPES, true)) {
                $this->error("Unknown insight type: {$type}");

                return self::FAILURE;
            }
        }

        $dry = (bool) $this->option('dry-run');
        $generated = 0;
        $skipped = 0;

        Site::withoutGlobalScope('organization')
            ->when($this->option('site'), fn ($q) => $q->whereKey($this->option('site')))
            ->whereNull('archived_at')
            ->where('connection_status', 'connected')
            ->with('organization')
            ->chunkById(50, function ($sites) use ($contexts, $generator, $usage, $tenancy, $types, $dry, &$generated, &$skipped) {
                foreach ($sites as $site) {
                    $org = $site->organization;

                    if (! $org || $org->deletion_scheduled_for !== null) {
                        continue;
                    }

                    // The generator and assembler both rely on tenant scope.
                    $tenancy->setOrganization($org);

                    foreach ($types as $type) {
                        if (! $usage->canGenerateInsight($org)) {
                            $this->line("org {$org->id}: AI insight limit reached, skipping remaining types");
                            break;
                        }

                        if ($this->hasFreshInsight($site->id, $type)) {
                            $skipped++;
                            continue;
                        }

                        $range = DateRange::fromRequest('last_30_days', null, null, $site->timezone ?: config('app.timezone', 'UTC'));
                        $context = $contexts->assemble($type, $site, $range, null);

                        if (! $context || ! $context['has_data']) {
                            $skipped++;
                            continue;
                        }

                        if ($dry) {
                            $this->line("would generate {$type} for site {$site->id}");
                            continue;
                        }

                        if ($generator->generateContextual($site, $type, $context)) {
                            $usage->recordInsight($org);
                            $generated++;
                        }
                    }
                }
            });

        $this->info($dry
            ? 'Proactive insights: dry run complete.'
            : "Proactive insights: {$generated} generated, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * A live insight of this type already inside its freshness window.
     *
     * Dismissed insights do not count — the user said "not now", not
     * "never tell me again", so the next cycle may legitimately re-raise it.
     */
    private function hasFreshInsight(int $siteId, string $type): bool
    {
        return AiInsight::withoutGlobalScope('organization')
            ->where('site_id', $siteId)
            ->where('type', $type)
            ->whereNull('dismissed_at')
            ->where(function ($q) {
                $q->where('expires_at', '>', now())
                  // Legacy rows predate the column; fall back to age.
                  ->orWhere(fn ($q2) => $q2->whereNull('expires_at')->where('created_at', '>', now()->subDay()));
            })
            ->exists();
    }
}
