<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches Cloudflare Traffic Insights for a site with a linked zone, using
 * the same GraphQL query and response shape as the WordPress plugin's
 * Insightistic_Cloudflare::get_direct_dashboard_data() (the Advanced/BYO
 * path) — see insightistic/docs/APP-CONNECT-WORKFLOW.md §9-10. Both data
 * sources normalize to the identical payload so the plugin's dashboard tab,
 * Traffic Gap callout, security monitor, and AI narrative don't need to
 * know which one served the data.
 *
 * Same schema-confidence caveat as the plugin side: these GraphQL field
 * names follow Cloudflare's publicly documented Analytics schema but have
 * not been exercised against a live zone from this codebase. A field-name
 * mismatch surfaces as a `null` return (logged) rather than a fatal.
 */
class CloudflareTrafficService
{
    private const GRAPHQL_ENDPOINT = 'https://api.cloudflare.com/client/v4/graphql';

    /**
     * @param  int  $days  Window size in days (clamped to 1-90).
     * @return array|null Normalized payload, or null on any failure (logged).
     */
    public function fetch(Site $site, int $days): ?array
    {
        $days  = max(1, min(90, $days));
        $until = now()->toDateString();
        $since = now()->subDays($days)->toDateString();

        $query = <<<'GRAPHQL'
        query GetZoneAnalytics($zoneTag: string!, $since: Date!, $until: Date!, $sinceTime: Time!, $untilTime: Time!, $rows: Int!) {
            viewer {
                zones(filter: { zoneTag: $zoneTag }) {
                    httpRequests1dGroups(limit: $rows, filter: { date_geq: $since, date_leq: $until }, orderBy: [date_ASC]) {
                        dimensions { date }
                        sum {
                            requests
                            cachedRequests
                            cachedBytes
                            bytes
                            threats
                            pageViews
                            encryptedRequests
                            countryMap { clientCountryName requests threats }
                            responseStatusMap { edgeResponseStatus requests }
                            clientSSLMap { clientSSLProtocol requests }
                            browserMap { uaBrowserFamily pageViews }
                        }
                        uniq { uniques }
                    }
                    firewallEventsAdaptiveGroups(limit: 20, filter: { datetime_geq: $sinceTime, datetime_leq: $untilTime }, orderBy: [count_DESC]) {
                        count
                        dimensions { action clientCountryName }
                    }
                }
            }
        }
        GRAPHQL;

        $variables = [
            'zoneTag'   => $site->cloudflare_zone_id,
            'since'     => $since,
            'until'     => $until,
            'sinceTime' => $since . 'T00:00:00Z',
            'untilTime' => $until . 'T23:59:59Z',
            'rows'      => $days + 1,
        ];

        try {
            $response = Http::withToken($site->cloudflare_api_token)
                ->timeout(20)
                ->retry(2, 1000, fn ($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException)
                ->post(self::GRAPHQL_ENDPOINT, ['query' => $query, 'variables' => $variables]);
        } catch (\Throwable $e) {
            Log::warning('Cloudflare Traffic Insights request exception', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            return null;
        }

        if (! $response->successful()) {
            Log::warning('Cloudflare Traffic Insights HTTP error', ['site_id' => $site->id, 'status' => $response->status()]);
            return null;
        }

        $body = $response->json();
        if (! empty($body['errors'])) {
            Log::warning('Cloudflare Traffic Insights GraphQL error', ['site_id' => $site->id, 'errors' => $body['errors']]);
            return null;
        }

        return $this->normalize($body['data'] ?? []);
    }

    private function normalize(array $data): array
    {
        $zone   = $data['viewer']['zones'][0] ?? [];
        $groups = $zone['httpRequests1dGroups'] ?? [];
        $fw     = $zone['firewallEventsAdaptiveGroups'] ?? [];

        $timeline = [];
        $totals   = [
            'requests'           => 0,
            'cached_requests'    => 0,
            'cached_bytes'       => 0,
            'bytes'              => 0,
            'threats'            => 0,
            'page_views'         => 0,
            'encrypted_requests' => 0,
            'uniques'            => 0,
        ];
        $countries    = [];
        $statusCodes  = [];
        $tlsVersions  = [];
        $browsers     = [];

        foreach ($groups as $row) {
            $sum = $row['sum'] ?? [];

            $timeline[] = [
                'date'            => $row['dimensions']['date'] ?? '',
                'requests'        => (int) ($sum['requests'] ?? 0),
                'cached_requests' => (int) ($sum['cachedRequests'] ?? 0),
                'threats'         => (int) ($sum['threats'] ?? 0),
                // Per-day page views/uniques were already being fetched but
                // only accumulated into $totals. The daily figures are what
                // the Cloudflare→traffic_daily sync stores, so surface them
                // here too; existing timeline consumers are unaffected.
                'page_views'      => (int) ($sum['pageViews'] ?? 0),
                'uniques'         => (int) ($row['uniq']['uniques'] ?? 0),
            ];

            $totals['requests']           += (int) ($sum['requests'] ?? 0);
            $totals['cached_requests']    += (int) ($sum['cachedRequests'] ?? 0);
            $totals['cached_bytes']       += (int) ($sum['cachedBytes'] ?? 0);
            $totals['bytes']              += (int) ($sum['bytes'] ?? 0);
            $totals['threats']            += (int) ($sum['threats'] ?? 0);
            $totals['page_views']         += (int) ($sum['pageViews'] ?? 0);
            $totals['encrypted_requests'] += (int) ($sum['encryptedRequests'] ?? 0);
            $totals['uniques']            += (int) ($row['uniq']['uniques'] ?? 0);

            foreach ((array) ($sum['countryMap'] ?? []) as $c) {
                $name = $c['clientCountryName'] ?? '';
                if ($name === '') {
                    continue;
                }
                $countries[$name] ??= ['requests' => 0, 'threats' => 0];
                $countries[$name]['requests'] += (int) ($c['requests'] ?? 0);
                $countries[$name]['threats']  += (int) ($c['threats'] ?? 0);
            }
            foreach ((array) ($sum['responseStatusMap'] ?? []) as $s) {
                $code = (string) ($s['edgeResponseStatus'] ?? '');
                if ($code === '') {
                    continue;
                }
                $statusCodes[$code] = ($statusCodes[$code] ?? 0) + (int) ($s['requests'] ?? 0);
            }
            foreach ((array) ($sum['clientSSLMap'] ?? []) as $t) {
                $proto = $t['clientSSLProtocol'] ?? '';
                if ($proto === '') {
                    continue;
                }
                $tlsVersions[$proto] = ($tlsVersions[$proto] ?? 0) + (int) ($t['requests'] ?? 0);
            }
            foreach ((array) ($sum['browserMap'] ?? []) as $b) {
                $family = $b['uaBrowserFamily'] ?? '';
                if ($family === '') {
                    continue;
                }
                $browsers[$family] = ($browsers[$family] ?? 0) + (int) ($b['pageViews'] ?? 0);
            }
        }

        arsort($countries);
        arsort($statusCodes);
        arsort($tlsVersions);
        arsort($browsers);

        $firewallEvents = [];
        foreach ($fw as $row) {
            $firewallEvents[] = [
                'action'  => $row['dimensions']['action'] ?? '',
                'country' => $row['dimensions']['clientCountryName'] ?? '',
                'count'   => (int) ($row['count'] ?? 0),
            ];
        }

        return [
            'totals'          => $totals,
            'cache_ratio'     => $totals['requests'] > 0 ? round(($totals['cached_requests'] / $totals['requests']) * 100, 1) : 0,
            'encrypted_ratio' => $totals['requests'] > 0 ? round(($totals['encrypted_requests'] / $totals['requests']) * 100, 1) : 0,
            'timeline'        => $timeline,
            'top_countries'   => array_slice($countries, 0, 10, true),
            'status_codes'    => array_slice($statusCodes, 0, 10, true),
            'tls_versions'    => $tlsVersions,
            'top_browsers'    => array_slice($browsers, 0, 8, true),
            'firewall_events' => array_slice($firewallEvents, 0, 20),
        ];
    }
}
