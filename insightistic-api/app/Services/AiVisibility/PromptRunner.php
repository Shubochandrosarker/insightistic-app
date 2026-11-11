<?php

namespace App\Services\AiVisibility;

use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityMention;
use App\Models\AiVisibilityPrompt;
use App\Models\AiVisibilityRun;
use App\Models\Site;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ProviderResolver;
use App\Services\Ai\RuleProvider;
use Illuminate\Support\Facades\Log;

/**
 * Runs one AI-visibility prompt against one site (Phase 11, doc 14).
 *
 * PROVIDER CHAIN: goes through the app's EXISTING swappable AiProvider
 * binding — wpistic gateway → OpenAI → $0 rule fallback, all wrapped in the
 * MeteredAiProvider telemetry decorator — and then through
 * ProviderResolver::resolve() so an organization that turned external AI
 * off is forced onto the rule provider, exactly like InsightGenerator
 * (docs/CONVERSATIONAL-ANALYTICS-AUDIT.md §3). There is deliberately NO
 * parallel HTTP client to model vendors here.
 *
 * JSON CONTRACT: the shared providers ask for a JSON object, so the run
 * asks for {"answer": "<full answer text>"} and extracts that one key —
 * the prompt itself is passed verbatim as user context.
 *
 * USAGE ACCOUNTING (doc 14: "cost is metered before and after run"):
 * aggregate spend/latency/failure for this call is metered by the
 * MeteredAiProvider wrapper automatically (kind: 'insight' telemetry).
 * The per-run tokens_used/cost_units columns record ONLY what the provider
 * payload actually reports (key "usage" / "cost_usd"), defaulting to 0 —
 * the AiProvider contract does not expose usage, and MeteredAiProvider's
 * rule applies here too: an estimated number in a cost table is worse than
 * an honest zero.
 *
 * FAILURE ISOLATION: a throwing or empty provider response writes ONE
 * status=failed run row and rethrows nothing — the scheduled sweep in
 * RunAiVisibilityJob continues with the remaining prompts (doc 14: AI
 * outputs are non-deterministic; one outage must not sink the batch).
 */
class PromptRunner
{
    private const SYSTEM = <<<'TXT'
Answer as a typical AI assistant would when a user asks this. Include source
links only if naturally available.
Return ONLY a JSON object with a single key "answer" containing your full
answer as plain text (inline links are fine).
TXT;

    public function __construct(
        private AiProvider $provider, // container binding = MeteredAiProvider(wpistic_gateway → openai → rule)
        private ProviderResolver $providerResolver,
        private MentionExtractor $extractor,
    ) {}

    /**
     * Run one prompt once and persist the observation (run + mentions +
     * citations). Advances the prompt's last_run_at on success AND failure —
     * a failed run consumed quota, so the daily due-pick must not re-fire it
     * within the same day and hammer a broken provider.
     */
    public function run(AiVisibilityPrompt $prompt, Site $site): AiVisibilityRun
    {
        $now = now();
        $resolved = null;

        try {
            $resolved = $this->providerResolver->resolve($this->provider, $site);

            $raw = $resolved->generate(
                self::SYSTEM,
                // The monitored question goes verbatim; nothing else is sent.
                ['prompt' => $prompt->prompt],
                $site->organization_id,
            );

            $answer = is_array($raw) ? trim((string) ($raw['answer'] ?? '')) : '';

            if ($raw === null || $answer === '') {
                return $this->recordFailure($prompt, $site, $resolved, $now, 'AI provider returned no usable answer.');
            }

            $competitors = AiVisibilityCompetitor::query()
                ->where('site_id', $site->id)
                ->orderBy('id')
                ->get();

            $extracted = $this->extractor->extract($answer, $site, $competitors);

            $run = AiVisibilityRun::query()->create([
                'site_id'         => $site->id,
                'prompt_id'       => $prompt->id,
                'provider'        => $this->providerName($resolved),
                'model'           => mb_substr($resolved->model(), 0, 64),
                'status'          => AiVisibilityRun::STATUS_SUCCESS,
                'response_hash'   => md5($answer),
                'tokens_used'     => $this->tokensFrom($raw, $resolved),
                'cost_units'      => $this->costFrom($raw, $resolved),
                'brand_mentioned' => $extracted['brand_mentioned'],
                'checked_at'      => $now,
                'created_at'      => $now,
            ]);

            // createMany through the relation fills run_id only — the
            // denormalized site_id and grain-style created_at are set here.
            $run->mentions()->createMany(array_map(
                fn (array $mention) => $mention + ['site_id' => $site->id, 'created_at' => $now],
                $extracted['mentions'],
            ));
            $run->citations()->createMany(array_map(
                fn (array $citation) => $citation + ['site_id' => $site->id, 'created_at' => $now],
                $extracted['citations'],
            ));

            $prompt->forceFill(['last_run_at' => $now])->save();

            return $run;
        } catch (\Throwable $e) {
            Log::warning('AI visibility run failed', [
                'prompt_id' => $prompt->id,
                'site_id'   => $site->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->recordFailure($prompt, $site, $resolved, $now, $e->getMessage());
        }
    }

    /**
     * The provider label for the run row: the provider that ACTUALLY ran,
     * mirroring AppServiceProvider's binding names ('rule' for the
     * RuleProvider — including the ProviderResolver consent fallback —
     * 'wpistic_gateway' for the gateway model prefix, else the
     * OpenAI-compatible provider). Acceptance (doc 14): every run carries
     * provider/model/surface metadata.
     */
    private function providerName(AiProvider $provider): string
    {
        if ($provider instanceof RuleProvider || str_starts_with($provider->model(), 'rule-based')) {
            return 'rule';
        }

        if (str_starts_with($provider->model(), 'wpistic-gateway:')) {
            return 'wpistic_gateway';
        }

        if (str_starts_with($provider->model(), 'openrouter:')) {
            return 'openrouter';
        }

        return 'openai';
    }

    /** Usage reported in the provider payload or by the provider (ReportsUsage), or an honest 0. */
    private function tokensFrom(array $raw, ?AiProvider $provider = null): int
    {
        $usage = $raw['usage'] ?? null;
        if (is_array($usage) && isset($usage['total_tokens'])) {
            return max(0, (int) $usage['total_tokens']);
        }

        $reported = $provider instanceof \App\Services\Ai\ReportsUsage ? $provider->lastUsage() : null;

        return $reported ? max(0, (int) ($reported['tokens_in'] ?? 0) + (int) ($reported['tokens_out'] ?? 0)) : 0;
    }

    /** Cost reported in the provider payload or by the provider (ReportsUsage), or an honest 0. */
    private function costFrom(array $raw, ?AiProvider $provider = null): float
    {
        $cost = $raw['cost_usd'] ?? null;
        if (is_numeric($cost)) {
            return max(0.0, (float) $cost);
        }

        $reported = $provider instanceof \App\Services\Ai\ReportsUsage ? $provider->lastUsage() : null;

        return is_numeric($reported['cost_usd'] ?? null) ? max(0.0, (float) $reported['cost_usd']) : 0.0;
    }

    /** The failed-run row a provider outage or exception leaves behind. */
    private function recordFailure(
        AiVisibilityPrompt $prompt,
        Site $site,
        ?AiProvider $resolved,
        $now,
        string $error,
    ): AiVisibilityRun {
        $run = AiVisibilityRun::query()->create([
            'site_id'         => $site->id,
            'prompt_id'       => $prompt->id,
            'provider'        => $resolved !== null ? $this->providerName($resolved) : 'unknown',
            'model'           => $resolved !== null ? mb_substr($resolved->model(), 0, 64) : null,
            'status'          => AiVisibilityRun::STATUS_FAILED,
            'response_hash'   => null,
            'tokens_used'     => 0,
            'cost_units'      => 0,
            'brand_mentioned' => false,
            'error'           => mb_substr($error, 0, 2000),
            'checked_at'      => $now,
            'created_at'      => $now,
        ]);

        $prompt->forceFill(['last_run_at' => $now])->save();

        return $run;
    }
}
