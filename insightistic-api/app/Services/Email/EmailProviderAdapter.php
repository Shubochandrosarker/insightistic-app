<?php

namespace App\Services\Email;

use App\Models\Site;

/**
 * One email platform Insightistic can read campaign analytics from.
 *
 * Adapters are responsible only for talking to their provider and returning
 * normalised rows; persistence, tenancy and connection state are handled by
 * EmailAnalyticsService, so adding a provider means implementing this
 * interface and registering it — nothing else.
 *
 * Providers that live inside WordPress (FluentCRM, AutomateWoo, WooCommerce)
 * have no adapter: the plugin pushes their figures through the connector
 * endpoint instead, because their data is not reachable from the SaaS.
 */
interface EmailProviderAdapter
{
    /** Stable key stored in email_campaigns.provider. */
    public function key(): string;

    /** Human-readable name for the UI. */
    public function label(): string;

    /**
     * Prove the credential works, and return a short non-secret description
     * of the connected account for display.
     *
     * @throws EmailProviderFailure
     */
    public function verify(string $credential): string;

    /**
     * Campaign/automation rows for a site, newest first.
     *
     * Each row uses email_campaigns column names. Counters a provider does
     * not report must be omitted (or null) rather than zeroed — the
     * dashboard distinguishes "not reported" from a real zero.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws EmailProviderFailure
     */
    public function fetchCampaigns(Site $site, string $credential, int $limit = 50): array;
}
