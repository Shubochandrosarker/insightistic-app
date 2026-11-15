<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunAiVisibilityJob;
use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityPrompt;
use App\Models\AiVisibilityRun;
use App\Models\Site;
use App\Services\AiVisibility\VisibilityMetrics;
use App\Services\UsageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AI Visibility API (Phase 11, doc 14). Reads over stored observations
 * (runs/mentions/citations) are open to anyone with site access, like every
 * other analytics surface. Writes — prompts, competitors, and queueing a
 * check — are owner/admin: prompts have recurring provider cost (doc 14:
 * "never automatically add unlimited prompts because each prompt has
 * recurring cost") and a check burns plan quota, matching every other
 * queued heavy action.
 *
 * QUOTA ENVELOPE: ai_prompt_runs_monthly denials return 422
 * ai_visibility_quota_reached in UsageService::limitError's shape
 * ({code, limit: {key, max, used}, message}) so the SPA's upgrade prompt
 * treats it like every other limit denial.
 *
 * EXPLAINABILITY (doc 14 acceptance: "UI can explain exactly how each
 * visibility metric is calculated"): the overview payload carries
 * metric_definitions — the exact formula behind every number — straight
 * from VisibilityMetrics::definitions().
 */
class AiVisibilityController extends Controller
{
    /** Hard prompt ceiling per site — cheap backstop under the plan quota. */
    private const MAX_ACTIVE_PROMPTS = 50;

    /** How many mention rows the mentions endpoint returns. */
    private const MENTIONS_LIMIT = 200;

    /**
     * GET /sites/{site}/ai-visibility — overview: metrics, prompts (each
     * with its latest run), competitors and the prompt-run quota.
     */
    public function overview(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $days = max(1, min(365, (int) $request->query('days', 30)));

        $metrics = app(VisibilityMetrics::class)->forSite($site, $days);

        return response()->json([
            'metrics'     => $metrics,
            'prompts'     => $this->promptRows($site),
            'competitors' => $this->competitorRows($site),
            'quota'       => $this->quota($site),
        ]);
    }

    /** GET /sites/{site}/ai-visibility/prompts — the prompt table. */
    public function prompts(Request $request): JsonResponse
    {
        $site = $this->site($request);

        return response()->json([
            'prompts' => $this->promptRows($site),
            'quota'   => $this->quota($site),
        ]);
    }

    /**
     * POST /sites/{site}/ai-visibility/prompts — track a prompt
     * (owner/admin, max 50 active per site).
     */
    public function storePrompt(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $validated = $request->validate([
            'prompt'   => ['required', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $used = AiVisibilityPrompt::query()
            ->where('site_id', $site->id)
            ->where('active', true)
            ->count();

        if ($used >= self::MAX_ACTIVE_PROMPTS) {
            return response()->json([
                'code'    => 'ai_prompt_limit_reached',
                'limit'   => ['key' => 'ai_visibility_prompts', 'max' => self::MAX_ACTIVE_PROMPTS, 'used' => $used],
                'message' => 'This site already tracks the maximum number of AI-visibility prompts.',
            ], 422);
        }

        $prompt = AiVisibilityPrompt::query()->create([
            'site_id'  => $site->id,
            'prompt'   => trim($validated['prompt']),
            'category' => $validated['category'] ?? null,
            'active'   => true,
        ]);

        return response()->json(['prompt' => [
            'id'       => $prompt->id,
            'prompt'   => $prompt->prompt,
            'category' => $prompt->category,
            'active'   => $prompt->active,
        ]], 201);
    }

    /**
     * DELETE /sites/{site}/ai-visibility/prompts/{prompt} — stop tracking
     * (owner/admin). Soft: active=false, run history kept (doc 14: repeated
     * observations over time are the product; deleting the prompt would
     * orphan them).
     */
    public function destroyPrompt(Request $request, int $siteId, int $prompt): JsonResponse
    {
        $site = $this->site($request);

        $row = AiVisibilityPrompt::query()
            ->where('site_id', $site->id)
            ->find($prompt);

        if (! $row) {
            return response()->json(['message' => 'Tracked prompt not found.'], 404);
        }

        $row->update(['active' => false]);

        return response()->json(['ok' => true]);
    }

    /**
     * GET|POST /sites/{site}/ai-visibility/prompts/{prompt}/check — queue a
     * single run (owner/admin). Guards mirror RankController::checkKeyword:
     * quota first (422 ai_visibility_quota_reached), then the same-day
     * cadence (422 already_checked_today — the daily cadence spaces spend;
     * the job re-checks both anyway, belt and braces).
     */
    public function checkPrompt(Request $request, int $siteId, int $prompt): JsonResponse
    {
        $site = $this->site($request);

        $row = AiVisibilityPrompt::query()
            ->where('site_id', $site->id)
            ->where('active', true)
            ->find($prompt);

        if (! $row) {
            return response()->json(['message' => 'Tracked prompt not found.'], 404);
        }

        $quota = $this->quota($site);

        // limit <= 0 means the plan grants no prompt runs at all — the same
        // "no allowance" reading CrawlScheduledCommand applies to its quota.
        if ($quota['limit'] <= 0 || $quota['used'] >= $quota['limit']) {
            return response()->json([
                'code'    => 'ai_visibility_quota_reached',
                'limit'   => ['key' => 'ai_prompt_runs_monthly', 'max' => $quota['limit'], 'used' => $quota['used']],
                'message' => 'Your plan limit for AI visibility prompt runs has been reached. Upgrade to continue.',
            ], 422);
        }

        // Rolling 24-hour cadence (mirrors RunAiVisibilityJob::duePrompts) —
        // calendar-day suppression re-fired prompts right after midnight.
        if ($row->last_run_at !== null && $row->last_run_at->gt(now()->subDay())) {
            return response()->json([
                'code'    => 'already_checked_today',
                'message' => 'This prompt was already checked in the last 24 hours.',
            ], 422);
        }

        RunAiVisibilityJob::dispatch($site->id, $row->id);

        return response()->json(['dispatched' => true]);
    }

    /**
     * GET /sites/{site}/ai-visibility/mentions?run_id= — the mention feed,
     * newest first, optionally scoped to one run.
     */
    public function mentions(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $mentions = \App\Models\AiVisibilityMention::query()
            ->where('site_id', $site->id)
            ->when($request->filled('run_id'), fn ($query) => $query->where('run_id', (int) $request->query('run_id')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MENTIONS_LIMIT)
            ->with(['run.prompt'])
            ->get()
            ->map(fn ($mention) => $this->mentionRow($mention))
            ->values();

        return response()->json(['mentions' => $mentions]);
    }

    /** GET /sites/{site}/ai-visibility/competitors — the competitor table. */
    public function competitors(Request $request): JsonResponse
    {
        $site = $this->site($request);

        return response()->json(['competitors' => $this->competitorRows($site)]);
    }

    /** POST /sites/{site}/ai-visibility/competitors — add a competitor (owner/admin). */
    public function storeCompetitor(Request $request): JsonResponse
    {
        $site = $this->site($request);

        if (! app(\App\Domain\Billing\EntitlementResolver::class)->hasFeature($site->organization, 'competitor_insights')) {
            return response()->json([
                'code' => 'feature_not_included',
                'feature' => 'competitor_insights',
                'message' => 'Competitor insights are included from AppSumo Tier 3 upward.',
            ], 402);
        }

        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:120'],
            'aliases'    => ['sometimes', 'nullable', 'array', 'max:20'],
            'aliases.*'  => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $name = trim($validated['name']);

        $exists = AiVisibilityCompetitor::query()
            ->where('site_id', $site->id)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            return response()->json([
                'code'    => 'competitor_exists',
                'message' => 'This competitor is already defined for the site.',
            ], 422);
        }

        $competitor = AiVisibilityCompetitor::query()->create([
            'site_id'      => $site->id,
            'name'         => $name,
            'aliases_json' => array_values(array_filter(array_map(
                fn ($alias) => trim((string) $alias),
                $validated['aliases'] ?? [],
            ), fn ($alias) => $alias !== '')),
        ]);

        return response()->json(['competitor' => [
            'id'      => $competitor->id,
            'name'    => $competitor->name,
            'aliases' => $competitor->aliases_json ?? [],
        ]], 201);
    }

    /**
     * DELETE /sites/{site}/ai-visibility/competitors/{competitor} — remove
     * (owner/admin). Hard delete is safe: matching happens at extraction
     * time, so stored mentions never change.
     */
    public function destroyCompetitor(Request $request, int $siteId, int $competitor): JsonResponse
    {
        $site = $this->site($request);

        if (! app(\App\Domain\Billing\EntitlementResolver::class)->hasFeature($site->organization, 'competitor_insights')) {
            return response()->json([
                'code' => 'feature_not_included',
                'feature' => 'competitor_insights',
                'message' => 'Competitor insights are included from AppSumo Tier 3 upward.',
            ], 402);
        }

        $row = AiVisibilityCompetitor::query()
            ->where('site_id', $site->id)
            ->find($competitor);

        if (! $row) {
            return response()->json(['message' => 'Competitor not found.'], 404);
        }

        $row->delete();

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------

    /**
     * Active prompts, each with its latest run (position: brand_mentioned —
     * what the UI's prompt table renders). One windowed query for the
     * latest runs, RankController-style.
     *
     * @return list<array<string, mixed>>
     */
    private function promptRows(Site $site): array
    {
        $prompts = AiVisibilityPrompt::query()
            ->where('site_id', $site->id)
            ->where('active', true)
            ->orderBy('id')
            ->get();

        $latestPerPrompt = [];

        if ($prompts->isNotEmpty()) {
            $latest = DB::table('ai_visibility_runs')
                ->select('id', 'prompt_id', 'status', 'brand_mentioned', 'provider', 'model', 'checked_at', 'rn')
                ->fromSub(function ($query) use ($prompts) {
                    $query->from('ai_visibility_runs')
                        ->selectRaw('id, prompt_id, status, brand_mentioned, provider, model, checked_at,
                            row_number() over (partition by prompt_id order by checked_at desc, id desc) as rn')
                        ->whereIn('prompt_id', $prompts->pluck('id'));
                }, 'ranked')
                ->where('rn', 1)
                ->get();

            foreach ($latest as $row) {
                $latestPerPrompt[(int) $row->prompt_id] = [
                    'id'              => (int) $row->id,
                    'status'          => $row->status,
                    'brand_mentioned' => (bool) $row->brand_mentioned,
                    'provider'        => $row->provider,
                    'model'           => $row->model,
                    'checked_at'      => $row->checked_at,
                ];
            }
        }

        return $prompts->map(fn (AiVisibilityPrompt $prompt) => [
            'id'          => $prompt->id,
            'prompt'      => $prompt->prompt,
            'category'    => $prompt->category,
            'active'      => $prompt->active,
            'last_run_at' => $prompt->last_run_at,
            'latest_run'  => $latestPerPrompt[$prompt->id] ?? null,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function competitorRows(Site $site): array
    {
        return AiVisibilityCompetitor::query()
            ->where('site_id', $site->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AiVisibilityCompetitor $competitor) => [
                'id'      => $competitor->id,
                'name'    => $competitor->name,
                'aliases' => $competitor->aliases_json ?? [],
            ])
            ->values()
            ->all();
    }

    /** One mention row plus its run/prompt context for the feed. */
    private function mentionRow($mention): array
    {
        $run = $mention->run;

        return [
            'id'             => $mention->id,
            'run_id'         => $mention->run_id,
            'entity_name'    => $mention->entity_name,
            'is_owned'       => $mention->is_owned,
            'is_competitor'  => $mention->is_competitor,
            'position_order' => $mention->position_order,
            'sentiment'      => $mention->sentiment,
            'created_at'     => $mention->created_at,
            'run'            => $run === null ? null : [
                'id'              => $run->id,
                'status'          => $run->status,
                'brand_mentioned' => $run->brand_mentioned,
                'provider'        => $run->provider,
                'model'           => $run->model,
                'checked_at'      => $run->checked_at,
                'prompt'          => $run->prompt?->prompt,
            ],
        ];
    }

    /**
     * The prompt-run quota state: this month's runs (ANY status — a failed
     * provider call is still a call somebody pays for) against the plan's
     * ai_prompt_runs_monthly.
     *
     * @return array{key: string, used: int, limit: int}
     */
    private function quota(Site $site): array
    {
        $org = $site->organization;

        return [
            'key'   => 'ai_prompt_runs_monthly',
            'used'  => AiVisibilityRun::query()
                ->where('site_id', $site->id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'limit' => $org ? app(UsageService::class)->limit($org, 'ai_prompt_runs_monthly') : 0,
        ];
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
