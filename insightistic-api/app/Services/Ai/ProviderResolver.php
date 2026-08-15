<?php

namespace App\Services\Ai;

use App\Models\Site;

/**
 * Applies an organization's `allow_external_ai` consent decision in front
 * of whichever AiProvider the container resolved from config. Extracted
 * from InsightGenerator (spec: docs/CONVERSATIONAL-ANALYTICS-AUDIT.md §3 —
 * the gap where InsightGenerator called an external provider regardless of
 * consent) so every AI call site — insights, and now Ask Insightistic V2
 * (P3) — enforces the same rule instead of re-deriving it.
 *
 * `CopilotChat::mode()` predates this class and does the equivalent check
 * inline for its own three-mode (openai/gateway/rule) dispatch; it is left
 * as-is rather than refactored onto this helper, to avoid regression risk
 * on already-shipped streaming chat code for an unrelated phase of work.
 */
class ProviderResolver
{
    /**
     * @param  AiProvider  $configured  The app-wide provider the container resolved from config (openai/gateway/rule, possibly metered).
     */
    public function resolve(AiProvider $configured, Site $site): AiProvider
    {
        if ($site->organization && ! $site->organization->allow_external_ai) {
            return new RuleProvider();
        }

        return $configured;
    }
}
