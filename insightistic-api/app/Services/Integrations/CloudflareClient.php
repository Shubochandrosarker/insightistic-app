<?php

namespace App\Services\Integrations;

use App\Models\Site;
use App\Models\TrafficDaily;
use App\Services\CloudflareTrafficService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare connection workflow.
 *
 * Cloudflare issues scoped API tokens rather than OAuth grants, so there is
 * no consent redirect: the user pastes a token with Zone Analytics: Read.
 * Credentials stay in the existing encrypted `sites.cloudflare_*` columns so
 * CloudflareTrafficService and the plugin's Advanced/BYO path keep working
 * against one source of truth instead of two.
 */
class CloudflareClient
{
    public function __construct(private readonly CloudflareTrafficService $traffic)
    {
    }

    /** Verify a token before storing it, so a typo fails at connect time. */
    public function verifyToken(string $token): void
    {
        $response = Http::withToken($token)
            ->timeout(config('insightistic.integrations.timeout'))
            ->get($this->base() . '/user/tokens/verify');

        if (! $response->successful() || ($response->json()['success'] ?? false) !== true) {
            throw new IntegrationFailure(
                'invalid_token',
                'Cloudflare rejected this API token. It needs the Zone → Analytics → Read permission.',
            );
        }
    }

    /**
     * Zones the token can see, for the picker.
     *
     * @return array<int,array{id:string,name:string,account:string}>
     */
    public function listZones(string $token): array
    {
        $response = Http::withToken($token)
            ->timeout(config('insightistic.integrations.timeout'))
            ->get($this->base() . '/zones', ['per_page' => 50]);

        if (! $response->successful()) {
            throw new IntegrationFailure('cloudflare_zones_failed', 'Could not list Cloudflare zones for this token.');
        }

        return collect($response->json()['result'] ?? [])
            ->map(fn (array $zone) => [
                'id'      => (string) ($zone['id'] ?? ''),
                'name'    => (string) ($zone['name'] ?? ''),
                'account' => (string) ($zone['account']['name'] ?? ''),
            ])
            ->filter(fn (array $zone) => $zone['id'] !== '')
            ->values()
            ->all();
    }

    /** Prove the stored token + selected zone actually return analytics. */
    public function testConnection(Site $site): array
    {
        $this->assertConfigured($site);

        $payload = $this->traffic->fetch($site, 7);

        if ($payload === null) {
            throw new IntegrationFailure(
                'cloudflare_test_failed',
                'Cloudflare returned no analytics for this zone. Check that the token covers this zone.',
            );
        }

        return ['zone_id' => $site->cloudflare_zone_id, 'days' => count($payload['timeline'] ?? [])];
    }

    /**
     * Pull daily Cloudflare traffic into `traffic_daily` under source
     * 'cloudflare', alongside (not instead of) GA4 and connector rows.
     *
     * @return array{days:int,latest_date:?string}
     */
    public function sync(Site $site, int $days = 30): array
    {
        $this->assertConfigured($site);

        $payload = $this->traffic->fetch($site, $days);

        if ($payload === null) {
            throw new IntegrationFailure(
                'cloudflare_sync_failed',
                'Cloudflare analytics were unavailable for this zone.',
                retryable: true,
            );
        }

        $latestDate = null;
        $written = 0;

        DB::transaction(function () use ($payload, $site, &$latestDate, &$written) {
            foreach ($payload['timeline'] ?? [] as $row) {
                $date = (string) ($row['date'] ?? '');
                if ($date === '') {
                    continue;
                }

                // Cloudflare counts requests and page views at the edge; it
                // has no notion of sessions or engagement, so only the fields
                // it genuinely measures are written.
                TrafficDaily::updateOrCreate(
                    ['site_id' => $site->id, 'source' => 'cloudflare', 'date' => $date],
                    [
                        'users'      => (int) ($row['uniques'] ?? 0),
                        'page_views' => (int) ($row['page_views'] ?? 0),
                    ],
                );

                $written++;
                if ($latestDate === null || $date > $latestDate) {
                    $latestDate = $date;
                }
            }
        });

        return ['days' => $written, 'latest_date' => $latestDate];
    }

    private function assertConfigured(Site $site): void
    {
        if (! $site->cloudflare_api_token) {
            throw new IntegrationFailure('not_connected', 'Connect Cloudflare for this site first.');
        }

        if (! $site->cloudflare_zone_id) {
            throw new IntegrationFailure('no_property_selected', 'Choose a Cloudflare zone for this site first.');
        }
    }

    private function base(): string
    {
        return rtrim((string) config('insightistic.integrations.cloudflare.api_base'), '/');
    }
}
