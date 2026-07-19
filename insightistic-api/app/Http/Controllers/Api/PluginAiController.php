<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Ai\AiProvider;
use App\Services\Ai\RuleProvider;
use App\Services\LicenseService;
use App\Services\UsageService;
use Illuminate\Http\Request;

/**
 * Serves the WordPress plugin's "Insightistic Cloud AI" provider — see
 * insightistic/docs/APP-CONNECT-WORKFLOW.md §8 for the frozen contract.
 *
 * Deliberately does NOT stand up a separate Ollama/Hermes integration.
 * Reuses the exact same AiProvider abstraction (bound in
 * AppServiceProvider) and the exact same monthly quota
 * (UsageService::canGenerateInsight) already powering the SaaS dashboard's
 * own "Generate Insight" button — whichever provider is configured via
 * INSIGHTISTIC_AI_PROVIDER serves both callers. The plugin's `model`
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
    ) {}

    public function generate(Request $request)
    {
        $data = $request->validate([
            'model'         => ['required', 'string', 'in:ollama-balanced,hermes-seo'],
            'skill_profile' => ['nullable', 'string', 'in:basic,seo_expert,commerce_expert'],
            'system_prompt' => ['required', 'string', 'max:8000'],
            'prompt'        => ['required', 'string', 'max:40000'],
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

        if ($this->provider instanceof RuleProvider) {
            // The $0 fallback only knows the SaaS dashboard's own
            // single-insight shape (title/summary/possible_reason/...), not
            // the plugin's {overall_score,key_insights,recommendations,
            // warnings} schema — fail clearly instead of returning a
            // payload the plugin can't render into anything meaningful.
            return $this->error(
                'ai_provider_unavailable',
                'Insightistic Cloud AI is not configured on the server yet. Try again later, or use a bring-your-own-key provider in the meantime.',
                503,
            );
        }

        if (! $this->usage->canGenerateInsight($org)) {
            return $this->error(
                'quota_exceeded',
                "You have used this period's free Insightistic Cloud AI quota. It resets automatically next month.",
                429,
            );
        }

        $result = $this->provider->generate($data['system_prompt'], [
            'engine'        => $data['model'],
            'skill_profile' => $data['skill_profile'] ?? 'basic',
            'instructions'  => $data['prompt'],
        ], $org->id);

        if ($result === null) {
            return $this->error('ai_error', 'Insightistic Cloud AI returned an error. Please try again.', 502);
        }

        $this->usage->recordInsight($org);

        return response()->json(['content' => json_encode($result)]);
    }

    private function error(string $code, string $message, int $status)
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
