<?php

namespace App\Services\Integrations;

use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\Site;

/**
 * One place that knows how to connect, verify, sync and disconnect a direct
 * data source, and that keeps `data_source_connections` in step so the
 * Integrations page, plugin diagnostics and Super Admin all tell the same
 * story about a source.
 *
 * Controllers and queue jobs both go through here, so a manual "Sync now"
 * and the nightly scheduled run record their outcome identically.
 */
class IntegrationManager
{
    public function __construct(
        private readonly GoogleOAuthService $google,
        private readonly Ga4Client $ga4,
        private readonly GscClient $gsc,
        private readonly CloudflareClient $cloudflare,
        private readonly BingConnectionService $bing,
        private readonly CloudflareAnalytics $cloudflareAnalytics,
    ) {
    }

    /** Store a freshly exchanged Google grant for one (site, source). */
    public function storeGoogleGrant(Site $site, string $source, array $tokens, ?string $email, int $userId): DataSourceCredential
    {
        $credential = DataSourceCredential::withoutGlobalScope('organization')->updateOrCreate(
            ['site_id' => $site->id, 'source' => $source],
            [
                'organization_id'      => $site->organization_id,
                'provider'             => 'google',
                'access_token'         => $tokens['access_token'],
                'refresh_token'        => $tokens['refresh_token'],
                'token_expires_at'     => now()->addSeconds($tokens['expires_in']),
                'scopes_json'          => array_values(array_filter(explode(' ', $tokens['scope'] ?? ''))),
                'external_account_id'  => $email,
                'connected_by_user_id' => $userId,
            ],
        );

        // Connected but not usable yet — no property chosen. The UI reads
        // this state to show the property picker rather than a green tick.
        $this->recordState($site, $source, 'degraded', [
            'connection_method'  => 'oauth',
            'label'              => $email,
            'last_error_code'    => 'no_property_selected',
            'last_error_message' => 'Choose a property to finish setting up this integration.',
        ]);

        return $credential;
    }

    /** Verify and store a Cloudflare API token on the site row. */
    public function connectCloudflare(Site $site, string $token): void
    {
        $this->cloudflare->verifyToken($token);

        $site->cloudflare_api_token = $token; // encrypted by the model cast
        $site->save();

        $this->recordState($site, 'cloudflare', 'degraded', [
            'connection_method'  => 'api_token',
            'last_error_code'    => 'no_property_selected',
            'last_error_message' => 'Choose a Cloudflare zone to finish setting up this integration.',
        ]);
    }

    /** Verify and store a Bing Webmaster API key (roadmap 11-BING-WEBMASTER). */
    public function connectBing(Site $site, string $apiKey, int $userId): DataSourceCredential
    {
        return $this->bing->connect($site, $apiKey, $userId);
    }

    /**
     * Properties/sites/zones the stored credential can see.
     *
     * @return array<int,array{id:string,name:string,account:string}>
     */
    public function listResources(Site $site, string $source): array
    {
        if ($source === 'cloudflare') {
            if (! $site->cloudflare_api_token) {
                throw new IntegrationFailure('not_connected', 'Connect Cloudflare for this site first.');
            }

            return $this->cloudflare->listZones((string) $site->cloudflare_api_token);
        }

        return match ($source) {
            'ga4'  => $this->ga4->listResources($this->credentialOrFail($site, $source)),
            'gsc'  => $this->gsc->listResources($this->credentialOrFail($site, $source)),
            'bing' => $this->bing->listResources($this->credentialOrFail($site, $source)),
            default => throw new IntegrationFailure('unsupported_source', "Source {$source} cannot be connected directly."),
        };
    }

    /** Persist the chosen property/site/zone and mark the source connected. */
    public function selectResource(Site $site, string $source, array $resource): void
    {
        if ($source === 'cloudflare') {
            $site->cloudflare_zone_id = $resource['id'];
            $site->cloudflare_account_id = $resource['account_id'] ?? $site->cloudflare_account_id;
            $site->save();
        } else {
            $credential = $this->credentialOrFail($site, $source);
            $credential->resource_json = $resource;
            $credential->save();
        }

        $this->recordState($site, $source, 'connected', [
            'label'              => $resource['name'] ?? $resource['id'],
            'last_error_code'    => null,
            'last_error_message' => null,
        ]);
    }

    /** Round-trip the provider without writing analytics rows. */
    public function test(Site $site, string $source): array
    {
        try {
            $result = match ($source) {
                'ga4'        => $this->ga4->testConnection($this->credentialOrFail($site, $source)),
                'gsc'        => $this->gsc->testConnection($this->credentialOrFail($site, $source)),
                'cloudflare' => $this->cloudflare->testConnection($site),
                'bing'       => $this->bing->testConnection($site, $this->credentialOrFail($site, $source)),
                default      => throw new IntegrationFailure('unsupported_source', "Source {$source} cannot be tested."),
            };

            $this->recordState($site, $source, 'connected', [
                'last_attempted_at'  => now(),
                'last_error_code'    => null,
                'last_error_message' => null,
            ]);

            return $result;
        } catch (IntegrationFailure $e) {
            $this->recordFailure($site, $source, $e);
            throw $e;
        }
    }

    /**
     * Pull data for one source and record the outcome either way.
     *
     * @return array<string,mixed> provider-specific counters
     */
    public function sync(Site $site, string $source, int $days = 30): array
    {
        $this->recordState($site, $source, 'syncing', ['last_attempted_at' => now()]);

        try {
            $result = match ($source) {
                'ga4'        => $this->ga4->sync($site, $this->credentialOrFail($site, $source), $days),
                'gsc'        => $this->gsc->sync($site, $this->credentialOrFail($site, $source), $days),
                'cloudflare' => $this->syncCloudflare($site, $days),
                'bing'       => $this->bing->sync($site, $this->credentialOrFail($site, $source)),
                default      => throw new IntegrationFailure('unsupported_source', "Source {$source} cannot be synced."),
            };

            $latest = $result['latest_date'] ?? null;

            $this->recordState($site, $source, 'connected', [
                'last_successful_at' => now(),
                'latest_data_at'     => $latest ? \Illuminate\Support\Carbon::parse($latest)->endOfDay() : null,
                'freshness'          => $this->freshness($latest),
                'last_error_code'    => null,
                'last_error_message' => null,
            ]);

            return $result;
        } catch (IntegrationFailure $e) {
            $this->recordFailure($site, $source, $e);
            throw $e;
        }
    }

    /** Revoke upstream where possible, then remove every local credential. */
    public function disconnect(Site $site, string $source): void
    {
        if ($source === 'cloudflare') {
            $site->cloudflare_api_token = null;
            $site->cloudflare_zone_id = null;
            $site->cloudflare_account_id = null;
            $site->save();
        } else {
            $credential = $this->credential($site, $source);

            if ($credential) {
                // Only Google grants have something to revoke upstream; an
                // API-key credential (Bing) is simply deleted locally.
                if ($credential->provider === 'google') {
                    $this->google->revoke($credential);
                }

                $credential->delete();
            }
        }

        $this->recordState($site, $source, 'not_configured', [
            'label'              => null,
            'last_error_code'    => null,
            'last_error_message' => null,
            'last_successful_at' => null,
            'latest_data_at'     => null,
            'freshness'          => 'unknown',
        ]);
    }

    /** True when the source has a usable credential and a chosen resource. */
    public function isConnected(Site $site, string $source): bool
    {
        if ($source === 'cloudflare') {
            return (bool) $site->cloudflare_api_token && (bool) $site->cloudflare_zone_id;
        }

        $credential = $this->credential($site, $source);

        return $credential !== null && ! empty($credential->resource_json['id']);
    }

    /** Non-secret connection detail for the Integrations page. */
    public function publicDetail(Site $site, string $source): ?array
    {
        if ($source === 'cloudflare') {
            if (! $site->cloudflare_api_token) {
                return null;
            }

            return [
                'connected_account' => $site->cloudflare_account_id,
                'resource'          => $site->cloudflare_zone_id ? ['id' => $site->cloudflare_zone_id] : null,
                'scopes'            => [],
                'connected_at'      => null,
            ];
        }

        return $this->credential($site, $source)?->publicSummary();
    }

    public function credential(Site $site, string $source): ?DataSourceCredential
    {
        return DataSourceCredential::where('site_id', $site->id)->where('source', $source)->first();
    }

    private function credentialOrFail(Site $site, string $source): DataSourceCredential
    {
        $credential = $this->credential($site, $source);

        if (! $credential) {
            throw new IntegrationFailure('not_connected', 'Connect this integration for the site first.');
        }

        return $credential;
    }

    /**
     * The Cloudflare sync arm: the existing nightly traffic pull into
     * `traffic_daily` (unchanged), followed by the Edge Intelligence daily
     * aggregates (roadmap 2026/15) into cloudflare_daily/cloudflare_bot_daily
     * via idempotent upserts. Plan-gated sub-datasets (firewall events, bot
     * analytics) degrade to flags inside CloudflareAnalytics rather than
     * failing the sync; only a hard failure of the primary 1dGroups dataset
     * throws (retryable, recorded like any other sync failure).
     *
     * @return array<string,mixed> provider-specific counters
     */
    private function syncCloudflare(Site $site, int $days): array
    {
        $result = $this->cloudflare->sync($site, $days);

        $edge = $this->cloudflareAnalytics->fetchDaily(
            $site,
            now()->subDays($days)->toDateString(),
            now()->toDateString(),
        );
        $stored = $this->cloudflareAnalytics->storeDaily($site, $edge);

        return $result + [
            'edge_days'           => $stored['days'],
            'edge_latest_date'    => $stored['latest_date'],
            'edge_bots_available' => $stored['bots_available'],
            'edge_flags'          => $edge['flags'],
        ];
    }

    private function recordFailure(Site $site, string $source, IntegrationFailure $e): void
    {
        // reauth_required is terminal until the user reconnects; everything
        // else is a degraded source that the next scheduled run may recover.
        $state = $e->errorCode === 'reauth_required' ? 'error' : 'degraded';

        $this->recordState($site, $source, $state, [
            'last_attempted_at'  => now(),
            'last_error_code'    => $e->errorCode,
            'last_error_message' => $e->getMessage(),
        ]);
    }

    private function recordState(Site $site, string $source, string $state, array $attrs = []): void
    {
        DataSourceConnection::recordAttempt(
            $site->organization_id,
            $site->id,
            $source,
            array_merge(['state' => $state], $attrs),
        );
    }

    private function freshness(?string $latestDate): string
    {
        if (! $latestDate) {
            return 'unknown';
        }

        $hours = (int) config('insightistic.integrations.stale_after_hours');

        return \Illuminate\Support\Carbon::parse($latestDate)->endOfDay()->diffInHours(now()) <= $hours
            ? 'fresh'
            : 'stale';
    }
}
