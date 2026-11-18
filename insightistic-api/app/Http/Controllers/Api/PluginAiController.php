<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\AiAllowanceGuard;
use App\Domain\Billing\AiCreditService;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Domain\Billing\EntitlementResolver;
use App\Http\Controllers\Controller;
use App\Models\AiAllowanceReservation;
use App\Models\Organization;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\ProviderResolver;
use App\Services\Ai\RuleProvider;
use App\Services\LicenseService;
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        private AiAvailability $availability,
        private AiAllowanceGuard $allowance,
        private EntitlementResolver $entitlements,
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
        if ($this->availability->isPaused($org, $site)) {
            return $this->availability->response();
        }

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
        $planSlug = (string) ($this->entitlements->resolve($org)['plan']['slug'] ?? $org->plan?->slug ?? 'free');
        // Preserve paid-plan standard requests and their existing overage
        // tariff. Free Connected plugin calls use the published 50-credit
        // plugin tariff instead of the SaaS dashboard tariff.
        $action = $planSlug === 'free'
            ? 'plugin_dashboard_insight'
            : 'basic_wordpress_dashboard_insight';
        $eligibleForStandard = $this->credits->standardEligible($org, $action);

        $usesStandardRequest = $creditModel
            && $this->credits->willUseStandardRequest($org, $action, $eligibleForStandard);

        $operationReference = $this->operationReference($request, $data);
        $reservation = null;

        if ($creditModel && ! $usesStandardRequest) {
            // Free Connected accounts are wallet-metered too. Reserve before
            // the model call so concurrent plugin requests cannot overspend;
            // the provider chain itself is still selected by the account tier.
            $decision = $this->allowance->reserve($org->id, $action, $operationReference);
            if (! $decision['allowed']) {
                return response()->json([
                    'code'             => 'insufficient_credits',
                    'message'          => 'Your Insightistic AI credits are finished for this period. Buy extra credits or wait for the monthly reset.',
                    'required_credits' => $decision['cost'],
                    'credits'          => $this->credits->walletSummary($org),
                ], 402);
            }

            $reservation = $decision['reservation'];
        }

        if (! $creditModel && ! $this->usage->canGenerateInsight($org)) {
            return $this->error(
                'quota_exceeded',
                "You have used this period's free Insightistic Cloud AI quota. It resets automatically next month.",
                429,
            );
        }

        try {
            $result = $provider->generate($data['system_prompt'], [
                'engine'        => $data['model'],
                'skill_profile' => $data['skill_profile'] ?? 'basic',
                'instructions'  => $data['prompt'],
            ], $org->id);
        } catch (\Throwable $e) {
            $this->allowance->release($reservation);
            Log::warning('plugin_ai.provider_exception', [
                'organization_id' => $org->id,
                'exception'       => $e::class,
            ]);

            return $this->error('ai_error', 'Insightistic Cloud AI is temporarily unavailable. Please try again shortly.', 502);
        }

        if ($result === null) {
            $this->allowance->release($reservation);
            return $this->error('ai_error', 'Insightistic Cloud AI returned an error. Please try again.', 502);
        }

        // A plan-aware chain may end in the deterministic provider after all
        // configured external providers fail. The plugin endpoint has a
        // different response schema, so do not return a mismatched insight.
        if (str_starts_with($provider->model(), 'rule-based')) {
            $this->allowance->release($reservation);
            return $this->error(
                'ai_provider_unavailable',
                'Insightistic Cloud AI is temporarily unavailable. Please try again shortly.',
                503,
            );
        }

        if ($usesStandardRequest) {
            try {
                $this->credits->charge($org, $action, $operationReference, true);
            } catch (InsufficientCreditsException) {
                // Standard slots ran out after the preflight; the answer is
                // already delivered, so log the shortfall rather than fail
                // a plugin call the user just paid attention to.
                Log::warning('plugin_ai.standard_charge_shortfall', [
                    'organization_id' => $org->id,
                ]);
            }
        } elseif (! $creditModel) {
            $this->usage->recordInsight($org);
        }
        if ($reservation !== null) {
            $this->allowance->commit($reservation);
        }

        $creditPayload = $creditModel ? $this->credits->walletSummary($org) : null;

        return response()->json([
            'content'     => json_encode($result),
            'credits'     => $creditPayload,
            'credit_cost' => $usesStandardRequest ? 0 : $this->credits->cost($action),
        ]);
    }

    /** Charge one downloadable report generated locally by the plugin. */
    public function chargeReport(Request $request)
    {
        $data = $request->validate([
            'operation_id' => ['sometimes', 'nullable', 'string', 'max:120'],
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

        $resolved = $this->entitlements->resolve($org);
        $planSlug = (string) ($resolved['plan']['slug'] ?? $org->plan?->slug ?? 'free');
        $used = (int) $this->usage->counter($org)->reports_generated;
        $freeLimit = (int) config('commercial.plugin_free_reports_monthly', 3);

        if ($planSlug === 'free' && $used >= $freeLimit) {
            return response()->json([
                'code'      => 'report_limit_reached',
                'message'   => "Free Connected accounts can download {$freeLimit} AI reports per month. Buy extra credits for more reports.",
                'reports'   => ['used' => $used, 'limit' => $freeLimit, 'remaining' => 0],
                'credits'   => $this->credits->walletSummary($org),
            ], 402);
        }

        $operationId = $data['operation_id']
            ?? $request->header('Idempotency-Key')
            ?? $request->header('X-Insightistic-Operation-Id');
        $siteId = $request->attributes->get('connector_site')?->id;
        $reference = $operationId
            ? 'plugin_report:' . $siteId . ':' . $operationId
            : 'plugin_report:' . $siteId . ':' . now()->format('Y-m') . ':' . ($used + 1);

        $decision = $this->allowance->reserve($org->id, 'plugin_ai_report', $reference);
        if (! $decision['allowed']) {
            return response()->json([
                'code'             => 'insufficient_credits',
                'message'          => 'Your Insightistic AI credits are finished for this period. Buy extra credits or wait for the monthly reset.',
                'required_credits' => $decision['cost'],
                'credits'          => $this->credits->walletSummary($org),
            ], 402);
        }

        $reservation = $decision['reservation'];
        $alreadyCommitted = $reservation?->status === AiAllowanceReservation::STATUS_COMMITTED;
        if (! $alreadyCommitted) {
            $settled = $this->allowance->commit($reservation);
            if (! $settled || $settled->status !== AiAllowanceReservation::STATUS_COMMITTED) {
                return response()->json([
                    'code'    => 'insufficient_credits',
                    'message' => 'Your Insightistic AI credits are not available for this report.',
                    'credits' => $this->credits->walletSummary($org),
                ], 402);
            }

            $this->usage->recordReport($org);
            $used++;
        }

        return response()->json([
            'ok'      => true,
            'charged' => $decision['cost'],
            'credits' => $this->credits->walletSummary($org),
            'reports' => [
                'used'      => $used,
                'limit'     => $planSlug === 'free' ? $freeLimit : null,
                'remaining' => $planSlug === 'free' ? max(0, $freeLimit - $used) : null,
            ],
        ]);
    }

    /** Stable per plugin operation: the caller's operation id when it sends one, else the request fingerprint. */
    private function operationReference(Request $request, array $data): string
    {
        $operationId = $data['operation_id']
            ?? $request->header('Idempotency-Key')
            ?? $request->header('X-Insightistic-Operation-Id');
        $siteId = $request->attributes->get('connector_site')?->id;

        return $operationId
            ? 'plugin_ai:' . $siteId . ':' . $operationId
            : 'plugin_ai:' . $siteId . ':' . sha1(json_encode([
                $data['model'], $data['skill_profile'] ?? 'basic', $data['system_prompt'], $data['prompt'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function error(string $code, string $message, int $status)
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
