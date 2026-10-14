<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\AiCreditService;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ProviderResolver;
use App\Services\Ai\RuleProvider;
use App\Services\LicenseService;
use App\Services\UsageService;
use Illuminate\Http\Request;

/**
 * Serves the WordPress plugin's "Insightistic Cloud AI" provider — see
 * insightistic/docs/APP-CONNECT-WORKFLOW.md §8 for the frozen contract.
 *
 * Deliberately does NOT stand up a separate Ollama/Hermes integration.
 * Reuses the exact same plan-aware AiProvider abstraction (bound in
 * AppServiceProvider) and the exact same monthly quota
 * (UsageService::canGenerateInsight) already powering the SaaS dashboard's
 * own "Generate Insight" button. The plugin's `model`
 * field (ollama-balanced|hermes-seo) and `skill_profile` are forwarded as
 * context so a future provider implementation can route on them; today
 * they don't change which provider answers, only the prompt/system text
 * the plugin already tailored client-side.
 */
class PluginAiController extends Controller
{
    public function __construct(
        private LicenseService $licenses,
        private UsageService $usage,
        private AiProvider $provider,
        private ProviderResolver $providerResolver,
        private AiCreditService $credits,
    ) {}

    public function generate(Request $request)
    {
        $data = $request->validate([
            'model'         => ['required', 'string', 'in:ollama-balanced,hermes-seo'],
            'skill_profile' => ['nullable', 'string', 'in:basic,seo_expert,commerce_expert'],
            'system_prompt' => ['required', 'string', 'max:8000'],
            'prompt'        => ['required', 'string', 'max:40000'],
            'operation_id'  => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        [$license] = $this->licenses->resolveFromConnector($request);
        if (! $license) {
            return $this->error('no_license', 'No active license for this site. Re-activate with your key.', 403);
        }

        $org = Organization::find($license->organization_id);
        $license->status = $this->licenses->computeStatus($org, $license);
        if (! $license->isEntitled()) {
            return $this->error('no_license', 'Your Insightistic account is not active. Please check your billing.', 403);
        }

        // Every other AI call site (InsightGenerator, AnswerSynthesizer,
        // InvestigationSynthesizer) resolves through ProviderResolver so an
        // org's allow_external_ai=false decision is honored before any data
        // leaves the server. This endpoint forwards up to 40,000 chars of
        // the site's own business data as a freeform prompt, so it must
        // follow the exact same rule rather than calling the configured
        // provider directly.
        $site = $request->attributes->get('connector_site');
        $provider = $site ? $this->providerResolver->resolve($this->provider, $site) : $this->provider;

        if ($provider instanceof RuleProvider) {
            // Reached either because no real provider is configured, or
            // because the org opted out of external AI — either way, the
            // $0/consent-off fallback only knows the SaaS dashboard's own
            // single-insight shape (title/summary/possible_reason/...), not
            // the plugin's {overall_score,key_insights,recommendations,
            // warnings} schema — fail clearly instead of returning a
            // payload the plugin can't render into anything meaningful.
            return $this->error(
                'ai_provider_unavailable',
                'Insightistic Cloud AI is not available for this site right now (unconfigured, or external AI has been disabled in Privacy settings).',
                503,
            );
        }

        $creditModel = $this->credits->walletEnabled($org);
        $action = 'basic_wordpress_dashboard_insight';
        $eligibleForStandard = $this->credits->standardEligible($org, $action);

        if ($creditModel && ! $this->credits->canCharge($org, $action, $eligibleForStandard)) {
            return $this->error(
                'insufficient_credits',
                'This AI action needs more Insight Credits. Buy a credit pack or upgrade your plan.',
                402,
            );
        }

        if (! $creditModel && ! $this->usage->canGenerateInsight($org)) {
            return $this->error(
                'quota_exceeded',
                "You have used this period's free Insightistic Cloud AI quota. It resets automatically next month.",
                429,
            );
        }

        $result = $provider->generate($data['system_prompt'], [
            'engine'        => $data['model'],
            'skill_profile' => $data['skill_profile'] ?? 'basic',
            'instructions'  => $data['prompt'],
        ], $org->id);

        if ($result === null) {
            return $this->error('ai_error', 'Insightistic Cloud AI returned an error. Please try again.', 502);
        }

        // A plan-aware chain may end in the deterministic provider after all
        // configured external providers fail. The plugin endpoint has a
        // different response schema, so do not return a mismatched insight.
        if (str_starts_with($provider->model(), 'rule-based')) {
            return $this->error(
                'ai_provider_unavailable',
                'Insightistic Cloud AI is temporarily unavailable. Please try again shortly.',
                503,
            );
        }

        if ($creditModel) {
            try {
                $operationId = $data['operation_id']
                    ?? $request->header('Idempotency-Key')
                    ?? $request->header('X-Insightistic-Operation-Id');
                $operationReference = $operationId
                    ? 'plugin_ai:' . $request->attributes->get('connector_site')?->id . ':' . $operationId
                    : 'plugin_ai:' . $request->attributes->get('connector_site')?->id . ':' . sha1(json_encode([
                        $data['model'], $data['skill_profile'] ?? 'basic', $data['system_prompt'], $data['prompt'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $this->credits->charge($org, $action, $operationReference, $eligibleForStandard);
            } catch (InsufficientCreditsException) {
                return $this->error(
                    'insufficient_credits',
                    'This AI action needs more Insight Credits. Buy a credit pack or upgrade your plan.',
                    402,
                );
            }
        } else {
            $this->usage->recordInsight($org);
        }

        return response()->json(['content' => json_encode($result)]);
    }

    private function error(string $code, string $message, int $status)
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
