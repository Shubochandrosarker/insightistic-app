<?php

namespace App\Jobs;

use App\Models\AiVisibilityPrompt;
use App\Models\AiVisibilityRun;
use App\Models\Site;
use App\Services\AiVisibility\PromptRunner;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The queue citizen around PromptRunner (Phase 11, doc 14): quota-guarded,
 * due-prompt picking, per-run isolation. Dispatched by the daily
 * insightistic:ai-visibility schedule entry or by the "check this prompt
 * now" API action.
 *
 * QUOTA (doc 14 cost controls: "stop when plan/credit allowance is
 * exhausted"): the plan's ai_prompt_runs_monthly caps runs per site per
 * month, counting EVERY run row written this month — failed runs included,
 * because a failed provider call is still a call somebody pays for. Over
 * budget → the job logs an info line and returns; no exception storm, no
 * retried failures (doc 14: don't overreact to one run).
 *
 * CADENCE: daily for every prompt in the MVP — a prompt is due when it has
 * never run or last ran before today (site timezone), so re-running the
 * command later the same day is a safe no-op (mirrors RankChecker's
 * same-day suppression). The monthly quota is the real cap; cadence only
 * spaces the spend out.
 *
 * tries=1 on purpose: a retried run would double-bill the provider for the
 * prompts already observed (same reasoning as RankCheckJob).
 */
class RunAiVisibilityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600; // several prompts x real provider latency

    public function __construct(
        public int $siteId,
        public ?int $promptId = null,
    ) {
    }

    public function handle(PromptRunner $runner): void
    {
        $site = Site::withoutGlobalScope('organization')->find($this->siteId);

        if ($site === null || $site->archived_at !== null) {
            return; // Site deleted or archived between queueing and running.
        }

        $org = $site->organization;

        if ($org === null || $org->deletion_scheduled_for !== null) {
            return;
        }

        $limit = (int) ($org->plan?->limit('ai_prompt_runs_monthly') ?? 0);
        $used = $this->runsThisMonth($site->id);
        $credits = app(\App\Domain\Billing\CreditLedger::class);

        if ($limit <= 0) {
            Log::info('AI visibility skipped: plan has no ai_prompt_runs_monthly allowance.', [
                'site_id' => $site->id,
            ]);

            return;
        }

        $overQuota = $used >= $limit;

        if ($overQuota && $credits->balance($org) < 1) {
            Log::info('AI visibility skipped: monthly prompt-run quota reached and no Insight Credits left.', [
                'site_id' => $site->id,
                'limit'   => $limit,
                'used'    => $used,
            ]);

            return;
        }

        foreach ($this->duePrompts($site, $this->promptId) as $prompt) {
            if ($used >= $limit) {
                // Plan quota exhausted → metered credit fallback (terms:
                // "credits power AI visibility runs"). One credit per run,
                // idempotent per run id; stop when the balance runs dry.
                try {
                    $run = $runner->run($prompt, $site);
                    $credits->consume(
                        $org, 1, 'ai_prompt_run_over_quota',
                        'ai_visibility_run', (string) $run->id,
                    );
                } catch (\App\Domain\Billing\Exceptions\InsufficientCreditsException) {
                    Log::info('AI visibility stopped mid-batch: out of credits.', [
                        'site_id'   => $site->id,
                        'prompt_id' => $prompt->id,
                    ]);

                    break;
                }

                continue;
            }

            // PromptRunner never throws on a provider failure — it records a
            // failed run — so one outage cannot sink the remaining prompts.
            $runner->run($prompt, $site);
            $used++;
        }
    }

    /** Runs (any status) written this calendar month for the site — the quota meter. */
    private function runsThisMonth(int $siteId): int
    {
        return AiVisibilityRun::query()
            ->where('site_id', $siteId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Active prompts due for a run: never run, or last run more than 24
     * hours ago (daily cadence). A rolling window, not calendar-day — a
     * 23:50 run must not re-fire at 00:10, and date-boundary math must not
     * make tests time-of-day flaky.
     *
     * @return \Illuminate\Support\Collection<int, AiVisibilityPrompt>
     */
    private function duePrompts(Site $site, ?int $promptId)
    {
        return AiVisibilityPrompt::query()
            ->where('site_id', $site->id)
            ->where('active', true)
            ->when($promptId !== null, fn ($query) => $query->whereKey($promptId))
            ->where(function ($query) {
                $query->whereNull('last_run_at')
                    ->orWhere('last_run_at', '<', now()->subDay());
            })
            ->orderBy('id')
            ->get();
    }
}
