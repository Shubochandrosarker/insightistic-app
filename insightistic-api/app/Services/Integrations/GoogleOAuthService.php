<?php

namespace App\Services\Integrations;

use App\Models\DataSourceCredential;
use App\Models\ProviderAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Server-side Google OAuth for data sources (GA4, Search Console).
 *
 * Separate from the social-login flow in OAuthController: different redirect
 * URI, read-only analytics scopes, and the resulting refresh token is stored
 * encrypted and never leaves the server. Consent is requested with
 * `access_type=offline` + `prompt=consent` so we actually receive a refresh
 * token — without it the grant dies in an hour and scheduled sync breaks.
 *
 * CSRF/interception protection: a single-use `state` nonce cached server-side
 * (there is no session cookie for the SPA), plus PKCE S256 so an intercepted
 * authorization code is useless without the verifier.
 */
class GoogleOAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';
    private const STATE_TTL_SECONDS = 600;

    /**
     * ORG-level consent URL (spec §5/§6): one authorization carrying every
     * read-only Google scope we need — Search Console + Analytics + the
     * userinfo identity scopes — so a second consent is never required when
     * the org adds more sites or sources later.
     */
    public function orgAuthorizeUrl(int $organizationId, int $userId): string
    {
        $this->assertConfigured();

        $state    = Str::random(40);
        $verifier = Str::random(96);

        Cache::put($this->stateKey($state), [
            'flow'     => 'org',
            'org_id'   => $organizationId,
            'user_id'  => $userId,
            'verifier' => $verifier,
        ], self::STATE_TTL_SECONDS);

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'             => config('insightistic.integrations.google.client_id'),
            'redirect_uri'          => config('insightistic.integrations.google.redirect'),
            'response_type'         => 'code',
            'scope'                 => implode(' ', $this->orgScopes()),
            'state'                 => $state,
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'include_granted_scopes' => 'true',
            'code_challenge'        => $this->challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Every read-only scope the product uses, for the org-level consent. */
    public function orgScopes(): array
    {
        $configured = config('insightistic.integrations.google.scopes', []);

        return array_values(array_unique(array_merge(
            $configured['gsc'] ?? [],
            $configured['ga4'] ?? [],
            ['openid', 'https://www.googleapis.com/auth/userinfo.email'],
        )));
    }

    /**
     * Build the consent URL and stash the state → {site, source, user,
     * verifier} binding. Returns the URL the browser should be sent to.
     */
    public function authorizeUrl(int $siteId, string $source, int $userId): string
    {
        $this->assertConfigured();

        $state = Str::random(40);
        $verifier = Str::random(96);

        Cache::put($this->stateKey($state), [
            'site_id'  => $siteId,
            'source'   => $source,
            'user_id'  => $userId,
            'verifier' => $verifier,
        ], self::STATE_TTL_SECONDS);

        $scopes = config("insightistic.integrations.google.scopes.{$source}", []);

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'             => config('insightistic.integrations.google.client_id'),
            'redirect_uri'          => config('insightistic.integrations.google.redirect'),
            'response_type'         => 'code',
            'scope'                 => implode(' ', $scopes),
            'state'                 => $state,
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'include_granted_scopes' => 'true',
            'code_challenge'        => $this->challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Validate and burn a state nonce. Returns the stashed binding, or null
     * when the state is unknown, already used, or expired.
     *
     * @return array{site_id:int,source:string,user_id:int,verifier:string}|null
     */
    public function consumeState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        $payload = Cache::pull($this->stateKey($state));

        return is_array($payload) ? $payload : null;
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{access_token:string,refresh_token:?string,expires_in:int,scope:string}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        $this->assertConfigured();

        $response = Http::asForm()
            ->timeout(config('insightistic.integrations.timeout'))
            ->post(self::TOKEN_URL, [
                'code'          => $code,
                'client_id'     => config('insightistic.integrations.google.client_id'),
                'client_secret' => config('insightistic.integrations.google.client_secret'),
                'redirect_uri'  => config('insightistic.integrations.google.redirect'),
                'grant_type'    => 'authorization_code',
                'code_verifier' => $verifier,
            ]);

        if (! $response->successful()) {
            throw new IntegrationFailure(
                'oauth_exchange_failed',
                'Google rejected the authorization code: ' . $this->googleError($response->json()),
            );
        }

        $body = $response->json();

        if (empty($body['refresh_token'])) {
            // Without a refresh token the connection would silently die in an
            // hour. Fail loudly at connect time instead of at 3am sync time.
            throw new IntegrationFailure(
                'missing_refresh_token',
                'Google did not return a refresh token. Remove Insightistic from your Google account permissions and connect again.',
            );
        }

        return [
            'access_token'  => (string) $body['access_token'],
            'refresh_token' => (string) $body['refresh_token'],
            'expires_in'    => (int) ($body['expires_in'] ?? 3600),
            'scope'         => (string) ($body['scope'] ?? ''),
        ];
    }

    /** The Google account {email, sub} behind an access token, for identity. */
    public function accountInfo(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)
            ->timeout(config('insightistic.integrations.timeout'))
            ->get(self::USERINFO_URL);

        if (! $response->successful()) {
            return null;
        }

        return [
            'email' => $response->json()['email'] ?? null,
            'sub'   => $response->json()['sub'] ?? null,
        ];
    }

    /** Back-compat shim: display email only. */
    public function accountEmail(string $accessToken): ?string
    {
        return $this->accountInfo($accessToken)['email'] ?? null;
    }

    /**
     * Return a valid access token, refreshing in place when it has expired.
     * Persists the refreshed token so concurrent jobs don't each re-refresh.
     *
     * Account-aware (spec §5): when the credential links to an org-level
     * provider account, the ACCOUNT holds the refresh token — refresh it,
     * mirror the fresh access token onto the credential, and return. The
     * per-site credential path stays for back-compat with grants created
     * before provider accounts existed.
     */
    public function freshAccessToken(DataSourceCredential $credential): string
    {
        if ($credential->provider_account_id && ($account = $credential->providerAccount)) {
            $token = $this->freshAccountToken($account);

            // Mirror onto the credential so existing readers (and
            // publicSummary) keep working unchanged.
            $credential->access_token = $account->access_token;
            $credential->token_expires_at = $account->token_expires_at;
            $credential->save();

            return $token;
        }

        return $this->freshLegacyCredentialToken($credential);
    }

    /** Refresh an org-level provider account's token in place. */
    public function freshAccountToken(ProviderAccount $account): string
    {
        if (! $account->needsRefresh()) {
            return (string) $account->access_token;
        }

        if (! $account->refresh_token) {
            throw new IntegrationFailure(
                'reauth_required',
                'This Google connection has no refresh token. Reconnect the integration.',
            );
        }

        $body = $this->refreshRequest($account->refresh_token);

        $account->access_token = (string) $body['access_token'];
        $account->token_expires_at = now()->addSeconds((int) ($body['expires_in'] ?? 3600));
        if (! empty($body['refresh_token'])) {
            $account->refresh_token = (string) $body['refresh_token'];
        }
        $account->save();

        return (string) $account->access_token;
    }

    private function freshLegacyCredentialToken(DataSourceCredential $credential): string
    {
        if (! $credential->needsRefresh()) {
            return (string) $credential->access_token;
        }

        if (! $credential->refresh_token) {
            throw new IntegrationFailure(
                'reauth_required',
                'This Google connection has no refresh token. Reconnect the integration.',
            );
        }

        $this->assertConfigured();

        $body = $this->refreshRequest($credential->refresh_token);

        $credential->access_token = (string) $body['access_token'];
        $credential->token_expires_at = now()->addSeconds((int) ($body['expires_in'] ?? 3600));
        // Google only re-issues a refresh token on rotation; keep the old one
        // when the response omits it, or the connection loses offline access.
        if (! empty($body['refresh_token'])) {
            $credential->refresh_token = (string) $body['refresh_token'];
        }
        $credential->save();

        return (string) $credential->access_token;
    }

    /** @return array{access_token:string,expires_in:int,refresh_token?:string} */
    private function refreshRequest(string $refreshToken): array
    {
        $response = Http::asForm()
            ->timeout(config('insightistic.integrations.timeout'))
            ->post(self::TOKEN_URL, [
                'client_id'     => config('insightistic.integrations.google.client_id'),
                'client_secret' => config('insightistic.integrations.google.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]);

        if (! $response->successful()) {
            // A revoked or expired grant is terminal — the user must reconnect.
            throw new IntegrationFailure(
                'reauth_required',
                'Google refused to refresh this connection: ' . $this->googleError($response->json()),
            );
        }

        return $response->json();
    }

    /** Best-effort revoke so disconnecting here also disconnects at Google. */
    public function revoke(DataSourceCredential $credential): void
    {
        // Linked credentials never hold the refresh token — revoke the
        // account's instead, without deleting the shared account.
        if ($credential->provider_account_id && ($account = $credential->providerAccount)) {
            $this->revokeToken($account->refresh_token ?: $account->access_token);

            return;
        }

        $this->revokeToken($credential->refresh_token ?: $credential->access_token);
    }

    public function revokeToken(?string $token): void
    {
        if (! $token) {
            return;
        }

        try {
            Http::asForm()
                ->timeout(config('insightistic.integrations.timeout'))
                ->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
        } catch (\Throwable $e) {
            // Local disconnect must succeed even when Google is unreachable;
            // the credential row is deleted by the caller regardless.
            logger()->warning('Google token revoke failed during disconnect.', ['error' => $e->getMessage()]);
        }
    }

    public function isConfigured(): bool
    {
        return (bool) config('insightistic.integrations.google.client_id')
            && (bool) config('insightistic.integrations.google.client_secret');
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new IntegrationFailure(
                'not_configured',
                'Google integration is not configured on this server. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.',
            );
        }
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function googleError(?array $body): string
    {
        return (string) ($body['error_description'] ?? $body['error'] ?? 'unknown error');
    }

    private function stateKey(string $state): string
    {
        return 'integration_oauth_state:' . hash('sha256', $state);
    }
}
