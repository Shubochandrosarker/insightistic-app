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
 * CopilotChat applies the same consent decision before it enters its own
 * streaming provider chain.
 */
class ProviderResolver
{
    /**
     * @param  AiProvider  $configured  The app-wide plan-aware provider chain.
     */
    public function resolve(AiProvider $configured, Site $site): AiProvider
    {
        if ($site->organization && ! $site->organization->allow_external_ai) {
            return new RuleProvider();
        }

        return $configured;
    }
}
