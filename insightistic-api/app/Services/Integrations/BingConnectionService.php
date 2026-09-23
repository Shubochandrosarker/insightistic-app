<?php

namespace App\Services\Integrations;

use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\SeoDaily;
use App\Models\SeoQuerySnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Bing Webmaster connection workflow (roadmap 11-BING-WEBMASTER-INDEXNOW).
 *
 * Bing has no OAuth: the user pastes an API key, the SaaS verifies it with a
 * GetUserSites round-trip, then the usual framework flow takes over —
 * resources picker, resource select, resync, disconnect all run through
 * IntegrationManager exactly like GA4/GSC/Cloudflare. The key is stored as
 * the credential's encrypted access token, so it enjoys the same
 * never-serialised protection as OAuth tokens (DataSourceCredential hides
 * token columns even from an accidental toArray()).
 *
 * Sync writes `seo_daily` and `seo_query_snapshots` under source 'bing',
 * alongside — never instead of — the Search Console rows. Bing only offers
 * weekly query buckets (no date-range parameter), so snapshots are keyed
 * period_start = period_end = the bucket's end date.
 */
class BingConnectionService
{
    public function __construct(private readonly BingWebmasterClient $client)
    {
    }

    /**
     * Verify an API key by listing sites, then store it for the site.
     *
     * @return DataSourceCredential the created/updated credential
     */
    public function connect(Site $site, string $apiKey, int $userId): DataSourceCredential
    {
        $this->client->sites($apiKey); // Invalid key fails here, before storage.

        $credential = DataSourceCredential::withoutGlobalScope('organization')->updateOrCreate(
            ['site_id' => $site->id, 'source' => 'bing'],
            [
                'organization_id'      => $site->organization_id,
                'provider'             => 'bing',
                'access_token'         => $apiKey,
                'scopes_json'          => [],
                'connected_by_user_id' => $userId,
                // resource_json is deliberately omitted: a re-connect keeps an
                // already-chosen site mapping, a fresh connect starts with none.
            ],
        );

        // Connected but not usable yet — no Bing site mapped (same degraded
        // semantics Google uses between grant and property selection).
        $this->recordState($site, 'degraded', [
            'connection_method'  => 'api_key',
            'last_error_code'    => 'no_property_selected',
            'last_error_message' => 'Choose a Bing Webmaster site to finish setting up this integration.',
        ]);

        return $credential;
    }

    /**
     * Sites the stored API key can see, for the picker.
     *
     * @return array<int,array{id:string,name:string,account:string}>
     */
    public function listResources(DataSourceCredential $credential): array
    {
        return $this->client->sites($this->apiKey($credential));
    }

    /** Prove the stored key still works and the mapped site is visible. */
    public function testConnection(Site $site, DataSourceCredential $credential): array
    {
        $siteUrl = $this->siteUrl($credential);
        $sites = $this->client->sites($this->apiKey($credential));

        $mapped = collect($sites)->first(fn (array $s) => $s['id'] === $siteUrl);

        if ($mapped === null) {
            throw new IntegrationFailure(
                'bing_site_not_visible',
                'The mapped Bing Webmaster site is no longer visible to this API key.',
            );
        }

        return ['site_url' => $siteUrl, 'sites' => count($sites)];
    }

    /**
     * Pull Bing performance into `seo_daily` (daily totals) and
     * `seo_query_snapshots` (weekly query buckets) under source 'bing'.
     *
     * Both writes are updateOrCreate upserts: Bing returns its whole history
     * on every call and has no date-range filter, so replaying is the
     * refresh mechanism — a nightly run repairs old buckets in place and
     * never duplicates rows.
     *
     * @return array{days:int,queries:int,latest_date:?string}
     */
    public function sync(Site $site, DataSourceCredential $credential): array
    {
        $apiKey = $this->apiKey($credential);
        $siteUrl = $this->siteUrl($credential);

        $latestDate = null;
        $dayRows = 0;

        $daily = $this->client->dailyTraffic($apiKey, $siteUrl);
        $weekly = $this->client->weeklyQueries($apiKey, $siteUrl);

        DB::transaction(function () use ($daily, $site, &$latestDate, &$dayRows) {
            foreach ($daily as $row) {
                $date = $row['date']->toDateString();

                SeoDaily::updateOrCreate(
                    ['site_id' => $site->id, 'source' => 'bing', 'date' => $date],
                    [
                        'clicks'      => $row['clicks'],
                        'impressions' => $row['impressions'],
                        // Bing's daily endpoint has no CTR/position figures;
                        // leave them null rather than write zeros.
                    ],
                );

                $dayRows++;
                if ($latestDate === null || $date > $latestDate) {
                    $latestDate = $date;
                }
            }
        });

        $queryRows = 0;

        DB::transaction(function () use ($weekly, $site, &$queryRows) {
            foreach ($weekly as $row) {
                $weekEnd = $row['week_end']->toDateString();
                $impressions = $row['impressions'];

                SeoQuerySnapshot::updateOrCreate(
                    [
                        'site_id'      => $site->id,
                        'source'       => 'bing',
                        'period_start' => $weekEnd,
                        'period_end'   => $weekEnd,
                        'query'        => mb_substr($row['query'], 0, 500),
                    ],
                    [
                        'clicks'      => $row['clicks'],
                        'impressions' => $impressions,
                        'ctr'         => $impressions > 0 ? round($row['clicks'] / $impressions, 4) : null,
                        'avg_click_position'      => $row['avg_click_position'],
                        'avg_impression_position' => $row['avg_impression_position'],
                        'created_at'              => now(),
                    ],
                );

                $queryRows++;
            }
        });

        return ['days' => $dayRows, 'queries' => $queryRows, 'latest_date' => $latestDate];
    }

    /** The decrypted key, or a clean failure when nothing is stored. */
    private function apiKey(DataSourceCredential $credential): string
    {
        $key = (string) $credential->access_token;

        if ($key === '') {
            throw new IntegrationFailure('not_connected', 'Connect Bing Webmaster for this site first.');
        }

        return $key;
    }

    private function siteUrl(DataSourceCredential $credential): string
    {
        $id = $credential->resource_json['id'] ?? null;

        if (! $id) {
            throw new IntegrationFailure('no_property_selected', 'Choose a Bing Webmaster site for this site first.');
        }

        return (string) $id;
    }

    private function recordState(Site $site, string $state, array $attrs = []): void
    {
        DataSourceConnection::recordAttempt(
            $site->organization_id,
            $site->id,
            'bing',
            array_merge(['state' => $state], $attrs),
        );
    }
}
