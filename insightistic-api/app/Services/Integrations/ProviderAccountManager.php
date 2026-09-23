<?php

namespace App\Services\Integrations;

use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Models\Site;
use App\Models\User;

/**
 * Org-level provider account lifecycle (spec §5): connect ONE Google (or
 * future Bing/Cloudflare/Shopify) authorization per organization, share it
 * across every site, attach per-site sources without a second consent, and
 * disconnect cleanly (revoke upstream, drop linked credentials).
 */
class ProviderAccountManager
{
    public function __construct(private readonly GoogleOAuthService $google)
    {
    }

    /**
     * Complete the org-level Google OAuth flow: exchange the code, identify
     * the Google account (email + sub), and upsert the shared grant. A
     * re-consent for the same Google account replaces the tokens in place
     * (updateOrCreate keyed on organization + provider + external id).
     */
    public function connectGoogle(Organization $org, int $userId, string $code, string $verifier): ProviderAccount
    {
        $tokens = $this->google->exchangeCode($code, $verifier);
        $info   = $this->google->accountInfo($tokens['access_token']);

        return static::upsertFromTokens($org, $tokens, $info, $userId);
    }

    public function googleAccount(Organization $org): ?ProviderAccount
    {
        return ProviderAccount::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('provider', 'google')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Create/refresh the shared org account from a completed token exchange
     * (used by both the org-level flow and the legacy site-level consent, so
     * the latter also ends with an org account for future sites).
     *
     * @param array{access_token:string,refresh_token:?string,expires_in:int,scope:string} $tokens
     * @param array{email:?string,sub:?string}|null $info
     */
    public static function upsertFromTokens(Organization $org, array $tokens, ?array $info, int $userId): ProviderAccount
    {
        return ProviderAccount::withoutGlobalScope('organization')->updateOrCreate(
            [
                'organization_id'     => $org->id,
                'provider'            => 'google',
                'external_account_id' => $info['sub'] ?? $info['email'] ?? ('unknown-' . $org->id),
            ],
            [
                'email'                => $info['email'] ?? null,
                'display_name'         => $info['email'] ?? 'Google account',
                'access_token'         => $tokens['access_token'],
                'refresh_token'        => $tokens['refresh_token'],
                'token_expires_at'     => now()->addSeconds($tokens['expires_in']),
                'scopes_json'          => array_values(array_filter(explode(' ', $tokens['scope'] ?? ''))),
                'connected_by_user_id' => $userId,
                'status'               => 'active',
            ],
        );
    }

    /**
     * Attach gsc/ga4 to a site using the org's shared Google grant — no new
     * consent. The credential keeps the resource selection; tokens come from
     * the linked provider account.
     */
    public function attachGoogleSource(Site $site, string $source, ProviderAccount $account, ?int $userId = null): DataSourceCredential
    {
        if (! in_array($source, ['gsc', 'ga4'], true)) {
            throw new IntegrationFailure('unsupported_source', "Source {$source} cannot be attached from a Google account.");
        }

        if ($account->organization_id !== $site->organization_id) {
            throw new IntegrationFailure('forbidden', 'This Google account belongs to another organization.');
        }

        $credential = DataSourceCredential::withoutGlobalScope('organization')->updateOrCreate(
            ['site_id' => $site->id, 'source' => $source],
            [
                'organization_id'      => $site->organization_id,
                'provider_account_id'  => $account->id,
                'provider'             => 'google',
                // Copy of the (already fresh) access token so GscClient /
                // Ga4Client can run immediately; refresh flows through the
                // account once it expires.
                'access_token'         => $account->access_token,
                'refresh_token'        => null,
                'token_expires_at'     => $account->token_expires_at,
                'scopes_json'          => $account->scopes_json ?? [],
                'external_account_id'  => $account->email,
                'connected_by_user_id' => $userId ?? $account->connected_by_user_id,
            ],
        );

        DataSourceConnection::recordAttempt($site->organization_id, $site->id, $source, [
            'state'             => $credential->resource_json ? 'connected' : 'degraded',
            'connection_method' => 'oauth',
            'label'             => $account->email,
            'last_error_code'   => $credential->resource_json ? null : 'no_property_selected',
            'last_error_message' => $credential->resource_json ? null : 'Choose a property to finish setting up this integration.',
        ]);

        return $credential;
    }

    /**
     * Disconnect the org's Google account: revoke the grant upstream
     * (best-effort), detach every linked credential, reset their connection
     * rows, delete the account.
     */
    public function disconnectGoogle(ProviderAccount $account): void
    {
        $this->google->revokeToken($account->refresh_token ?: $account->access_token);

        $credentials = DataSourceCredential::withoutGlobalScope('organization')
            ->where('provider_account_id', $account->id)
            ->get();

        foreach ($credentials as $credential) {
            DataSourceConnection::recordAttempt(
                $credential->organization_id,
                $credential->site_id,
                $credential->source,
                [
                    'state'              => 'not_configured',
                    'connection_method'  => null,
                    'label'              => null,
                    'last_error_code'    => null,
                    'last_error_message' => null,
                    'last_successful_at' => null,
                    'latest_data_at'     => null,
                    'freshness'          => 'unknown',
                ],
            );
            $credential->delete();
        }

        $account->delete();
    }

    /** Public status shape for the Integrations page. */
    public function googleStatus(Organization $org): array
    {
        $account = $this->googleAccount($org);

        if (! $account) {
            return [
                'connected'    => false,
                'configured'   => $this->google->isConfigured(),
                'connect_url'  => null,
                'account'      => null,
                'sites_using'  => 0,
            ];
        }

        return [
            'connected'    => true,
            'configured'   => true,
            'connect_url'  => null,
            'account'      => $account->publicSummary(),
            'sites_using'  => DataSourceCredential::withoutGlobalScope('organization')
                ->where('provider_account_id', $account->id)
                ->distinct('site_id')
                ->count('site_id'),
        ];
    }
}
