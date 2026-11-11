<?php

namespace App\Services\Rank;

use App\Models\RankKeyword;

/**
 * Pluggable SERP observation backend for the rank tracker (Phase 10, doc 13
 * §Build-vs-buy: never scrape SERPs in-process — everything goes through a
 * provider). The active implementation is chosen by RankProviderFactory
 * from config('insightistic.rank.provider') (env RANK_PROVIDER, default
 * "dataforseo"); tests bind fakes of this interface instead of stubbing HTTP.
 */
interface RankProvider
{
    /**
     * Check a batch of keywords in as few provider calls as possible and
     * return one result per keyword, keyed by rank_keywords.id.
     *
     * "Position" is always the tracked site's own domain: the first organic
     * result whose host matches the keyword's site. null means the site was
     * NOT FOUND in the provider's top 100 — a real observation, never a
     * provider error. Providers MUST throw (IntegrationFailure) on errors
     * instead of returning nulls, so RankChecker persists nothing on a bad
     * call and the last known position survives (doc 13 acceptance).
     *
     * @param  RankKeyword[]  $keywords
     * @return array<int, array{position: ?int, ranking_url: ?string, serp_features: ?array, provider_request_id: ?string, cost_units: float}>  keyed by keyword id
     *
     * @throws \App\Services\Integrations\IntegrationFailure when the provider call fails
     */
    public function checkBatch(array $keywords): array;

    /** Whether credentials are configured; false = skip checks, never bill. */
    public function available(): bool;

    /** Stable provider id stored on every observation (rank_observations.provider). */
    public function name(): string;
}
