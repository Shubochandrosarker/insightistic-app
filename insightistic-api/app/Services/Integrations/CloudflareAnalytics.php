<?php

namespace App\Services\Integrations;

use App\Models\CloudflareBotDaily;
use App\Models\CloudflareDaily;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Edge Intelligence (roadmap 2026/15): nightly fetch of the daily
 * edge aggregates that back the GET sites/{site}/cloudflare endpoint, stored
 * in cloudflare_daily / cloudflare_bot_daily so the endpoint never has to
 * call Cloudflare live.
 *
 * Zone/credential resolution matches CloudflareClient exactly: the encrypted
 * sites.cloudflare_api_token + cloudflare_zone_id columns (SaaS-hosted
 * connection; the plugin BYO path reads the same columns locally). No extra
 * zone-id helper was needed — the Site row already carries both.
 *
 * Degradation design (roadmap 15: "the UI must degrade gracefully rather
 * than pretending bot fields exist for every zone"):
 *  - The httpRequests1dGroups dataset is the primary source; a failure here
 *    is a hard sync failure (IntegrationFailure, retryable) — same semantics
 *    as CloudflareClient::sync.
 *  - firewallEventsAdaptiveGroups and httpRequestsAdaptiveGroups (bot
 *    analytics) are plan-gated datasets. Each is fetched as its OWN GraphQL
 *    request, so a plan-level validation error there cannot take the main
 *    document down with it; such errors are recognized and turned into flags
 *    (`firewall_unavailable`, empty bots with available=false) instead of
 *    exceptions. The UI renders these as "not available on this Cloudflare
 *    plan", never as errors (roadmap acceptance criteria).
 *
 * Field names follow the same publicly documented Analytics schema the
 * existing CloudflareTrafficService queries (same schema-confidence caveat:
 * not yet exercised against a live zone from this codebase; a mismatch
 * surfaces as a degraded/empty section, not a fatal).
 */
class CloudflareAnalytics
{
    /** Roadmap 15's AI crawler list — matched case-insensitively as UA substrings. */
    private const AI_CRAWLERS = [
        'gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'claude-web',
        'anthropic-ai', 'perplexitybot', 'google-extended', 'bytespider', 'ccbot',
    ];

    /** Search engine crawlers (roadmap 15) — classified search_bot. */
    private const SEARCH_BOTS = ['googlebot', 'bingbot'];

    /** Known SEO crawlers (roadmap 15 crawler list) — classified other. */
    private const KNOWN_OTHER_CRAWLERS = ['ahrefsbot', 'semrushbot'];

    /** GraphQL error markers that mean "dataset/field not on this plan" → degrade. */
    private const PLAN_ERROR_PATTERNS = [
        'unknown argument', 'unknown field', 'cannot query field',
        'not entitled', 'requires a paid', 'premium', 'plan',
    ];

    private const REASON_PLAN = 'plan_does_not_expose_bot_analytics';
    private const REASON_FAILED = 'bot_analytics_request_failed';

    public function fetchDaily(Site $site, string $from, string $to): array
    {
        $this->assertConfigured($site);

        $times = [
            'zoneTag'      => (string) $site->cloudflare_zone_id,
            'dateFrom'     => $from,
            'dateTo'       => $to,
            'datetimeFrom' => $from . 'T00:00:00Z',
            'datetimeTo'   => $to . 'T23:59:59Z',
        ];

        // Primary dataset: daily totals. Failure here fails the sync — there
        // is nothing honest to store without it.
        $traffic = $this->graphql($site, self::trafficQuery(), $times);

        if ($traffic['errors'] !== []) {
            throw new IntegrationFailure(
                'cloudflare_sync_failed',
                'Cloudflare edge analytics were unavailable for this zone.',
                retryable: true,
            );
        }

        $flags = [];

        // Plan-gated datasets, each in its own request so one plan error
        // degrades only its own section.
        $firewall = $this->graphql($site, self::firewallQuery(), $times);
        $firewallByDate = [];
        if ($firewall['errors'] !== []) {
            $flags[] = 'firewall_unavailable';
        } else {
            $firewallByDate = $this->normalizeFirewall($firewall['data']);
        }

        $bots = $this->fetchBots($site, $from, $to);
        if (! ($bots['available'] ?? false)) {
            $flags[] = 'bot_analytics_unavailable';
        }

        return [
            'rows'  => $this->normalizeDaily($traffic['data'], $firewallByDate),
            'bots'  => $bots,
            'flags' => $flags,
        ];
    }

    /**
     * Bot analytics from httpRequestsAdaptiveGroups — a higher-tier dataset
     * that does not exist on every Cloudflare plan. Any error (plan-gated or
     * otherwise) degrades to available=false; this method never throws.
     *
     * Rows are per (date, user agent), filtered to the crawlers roadmap 15
     * names. Unrecognized/empty user agents are dropped: storing every raw UA
     * string would flood the table and none of it is "bot intelligence".
     *
     * @return array{available:bool, rows:array, reason?:string}
     */
    public function fetchBots(Site $site, string $from, string $to): array
    {
        $result = $this->graphql($site, self::botsQuery(), [
            'zoneTag'      => (string) $site->cloudflare_zone_id,
            'datetimeFrom' => $from . 'T00:00:00Z',
            'datetimeTo'   => $to . 'T23:59:59Z',
        ]);

        if ($result['errors'] !== []) {
            return [
                'available' => false,
                'rows'      => [],
                'reason'    => $this->isPlanError($result['errors']) ? self::REASON_PLAN : self::REASON_FAILED,
            ];
        }

        $zones = $result['data']['viewer']['zones'] ?? [];
        $rows  = [];

        foreach ((array) ($zones[0]['httpRequestsAdaptiveGroups'] ?? []) as $group) {
            $ua = trim((string) ($group['dimensions']['clientRequestUserAgent'] ?? ''));
            $classification = $this->classify($ua);

            if ($classification === null) {
                continue; // empty or unrecognized UA
            }

            $rows[] = [
                'date'           => (string) ($group['dimensions']['date'] ?? ''),
                'bot_name'       => mb_substr($ua, 0, 64),
                'classification' => $classification,
                'requests'       => (int) ($group['count'] ?? 0),
                // Per-bot blocked counts need plan-level bot management
                // (botScore/botFightMode fields); the MVP stores request
                // volume only. Firewall action totals live on
                // cloudflare_daily.firewall_json for the Security section.
                'blocked'        => 0,
            ];
        }

        return ['available' => true, 'rows' => $rows];
    }

    /**
     * Idempotent persistence for the nightly sync: upsert on (site, date)
     * and (site, date, bot_name), so a replayed run updates rows in place
     * and never duplicates. Bot rows are written only when the dataset was
     * actually available — an unavailable plan never leaves stale rows
     * behind, and a plan upgrade starts filling them naturally.
     *
     * @return array{days:int, latest_date:?string, bots_available:bool}
     */
    public function storeDaily(Site $site, array $payload): array
    {
        $rows = $payload['rows'] ?? [];
        $bots = $payload['bots'] ?? [];

        $latest = null;

        DB::transaction(function () use ($site, $rows, $bots, &$latest) {
            foreach ($rows as $row) {
                $date = (string) ($row['date'] ?? '');
                if ($date === '') {
                    continue;
                }

                CloudflareDaily::updateOrCreate(
                    ['site_id' => $site->id, 'date' => $date],
                    collect($row)->except('date')->all(),
                );

                if ($latest === null || $date > $latest) {
                    $latest = $date;
                }
            }

            if (($bots['available'] ?? false) === true) {
                foreach ($bots['rows'] ?? [] as $bot) {
                    $date = (string) ($bot['date'] ?? '');
                    $name = (string) ($bot['bot_name'] ?? '');
                    if ($date === '' || $name === '') {
                        continue;
                    }

                    CloudflareBotDaily::updateOrCreate(
                        ['site_id' => $site->id, 'date' => $date, 'bot_name' => $name],
                        [
                            'classification' => (string) ($bot['classification'] ?? 'other'),
                            'requests'       => (int) ($bot['requests'] ?? 0),
                            'blocked'        => (int) ($bot['blocked'] ?? 0),
                        ],
                    );
                }
            }
        });

        return [
            'days'           => count($rows),
            'latest_date'    => $latest,
            'bots_available' => (bool) ($bots['available'] ?? false),
        ];
    }

    // ------------------------------------------------------------------
    // Normalization
    // ------------------------------------------------------------------

    /**
     * Normalize the 1dGroups document into cloudflare_daily row shapes.
     *
     * @param  array  $firewallByDate  {date: {action: events}} (empty when degraded)
     * @return array<int,array>
     */
    private function normalizeDaily(array $data, array $firewallByDate): array
    {
        $zones = $data['viewer']['zones'] ?? [];
        $rows  = [];

        foreach ((array) ($zones[0]['httpRequests1dGroups'] ?? []) as $group) {
            $date = (string) ($group['dimensions']['date'] ?? '');
            if ($date === '') {
                continue;
            }

            $sum   = $group['sum'] ?? [];
            $fourX = 0;
            $fiveX = 0;
            $statusBreakdown = [];

            foreach ((array) ($sum['responseStatusMap'] ?? []) as $status) {
                $code = (string) ($status['edgeResponseStatus'] ?? '');
                $count = (int) ($status['requests'] ?? 0);
                if ($code === '') {
                    continue;
                }

                $statusBreakdown[$code] = ($statusBreakdown[$code] ?? 0) + $count;

                if ($code >= 400 && $code < 500) {
                    $fourX += $count;
                } elseif ($code >= 500 && $code < 600) {
                    $fiveX += $count;
                }
            }

            $countries = [];
            foreach ((array) ($sum['countryMap'] ?? []) as $country) {
                $name = (string) ($country['clientCountryName'] ?? '');
                if ($name === '') {
                    continue;
                }
                $countries[$name] = ($countries[$name] ?? 0) + (int) ($country['requests'] ?? 0);
            }
            arsort($countries);
            $countries = array_slice($countries, 0, 10, true);

            $rows[] = [
                'date'                  => $date,
                'requests'              => (int) ($sum['requests'] ?? 0),
                'bytes'                 => (int) ($sum['bytes'] ?? 0),
                'cached_requests'       => (int) ($sum['cachedRequests'] ?? 0),
                'cached_bytes'          => (int) ($sum['cachedBytes'] ?? 0),
                'encrypted_requests'    => (int) ($sum['encryptedRequests'] ?? 0),
                'pageviews'             => (int) ($sum['pageViews'] ?? 0),
                'threats'               => (int) ($sum['threats'] ?? 0),
                'client_4xx'            => $fourX,
                'client_5xx'            => $fiveX,
                'countries_json'        => $countries,
                'status_breakdown_json' => $statusBreakdown,
                'firewall_json'         => $firewallByDate[$date] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Normalize firewallEventsAdaptiveGroups into {date: {action: events}}.
     *
     * @return array<string,array<string,int>>
     */
    private function normalizeFirewall(array $data): array
    {
        $zones = $data['viewer']['zones'] ?? [];
        $byDate = [];

        foreach ((array) ($zones[0]['firewallEventsAdaptiveGroups'] ?? []) as $group) {
            $date   = (string) ($group['dimensions']['date'] ?? '');
            $action = (string) ($group['dimensions']['action'] ?? '');
            if ($date === '' || $action === '') {
                continue;
            }

            $byDate[$date][$action] = ($byDate[$date][$action] ?? 0) + (int) ($group['count'] ?? 0);
        }

        return $byDate;
    }

    /** ai_crawler | search_bot | other | null (null = not a bot we claim to know). */
    private function classify(string $userAgent): ?string
    {
        $ua = mb_strtolower($userAgent);

        if ($ua === '') {
            return null;
        }

        foreach (self::AI_CRAWLERS as $needle) {
            if (str_contains($ua, $needle)) {
                return 'ai_crawler';
            }
        }

        foreach (self::SEARCH_BOTS as $needle) {
            if (str_contains($ua, $needle)) {
                return 'search_bot';
            }
        }

        foreach (self::KNOWN_OTHER_CRAWLERS as $needle) {
            if (str_contains($ua, $needle)) {
                return 'other';
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // GraphQL plumbing
    // ------------------------------------------------------------------

    /**
     * Run one GraphQL document. Never throws: transport/HTTP failures come
     * back as a synthetic errors entry so each caller decides degrade vs fail.
     *
     * @return array{data:array, errors:array}
     */
    private function graphql(Site $site, string $query, array $variables): array
    {
        try {
            $response = Http::withToken((string) $site->cloudflare_api_token)
                ->timeout(config('insightistic.integrations.timeout'))
                ->retry(2, 1000, fn ($exception) => $exception instanceof ConnectionException)
                ->post($this->endpoint(), ['query' => $query, 'variables' => $variables]);
        } catch (\Throwable $e) {
            Log::warning('Cloudflare Edge Analytics request exception', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return ['data' => [], 'errors' => [['message' => 'connection_failed: ' . $e->getMessage()]]];
        }

        if (! $response->successful()) {
            Log::warning('Cloudflare Edge Analytics HTTP error', ['site_id' => $site->id, 'status' => $response->status()]);

            return ['data' => [], 'errors' => [['message' => 'http_' . $response->status()]]];
        }

        $body = $response->json() ?? [];

        return [
            'data'   => $body['data'] ?? [],
            'errors' => $body['errors'] ?? [],
        ];
    }

    private function isPlanError(array $errors): bool
    {
        $messages = mb_strtolower(implode(' | ', array_map(
            fn ($error) => (string) ($error['message'] ?? ''),
            $errors,
        )));

        foreach (self::PLAN_ERROR_PATTERNS as $needle) {
            if (str_contains($messages, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('insightistic.integrations.cloudflare.api_base'), '/');

        return $base . '/graphql';
    }

    private function assertConfigured(Site $site): void
    {
        if (! $site->cloudflare_api_token || ! $site->cloudflare_zone_id) {
            throw new IntegrationFailure('not_connected', 'Connect Cloudflare for this site first.');
        }
    }

    private static function trafficQuery(): string
    {
        return <<<'GRAPHQL'
        query EdgeDaily($zoneTag: String!, $dateFrom: Date!, $dateTo: Date!) {
            viewer {
                zones(filter: { zoneTag: $zoneTag }) {
                    httpRequests1dGroups(limit: 100, filter: { date_geq: $dateFrom, date_leq: $dateTo }, orderBy: [date_ASC]) {
                        dimensions { date }
                        sum {
                            requests
                            bytes
                            cachedRequests
                            cachedBytes
                            encryptedRequests
                            pageViews
                            threats
                            countryMap { clientCountryName requests }
                            responseStatusMap { edgeResponseStatus requests }
                        }
                    }
                }
            }
        }
        GRAPHQL;
    }

    private static function firewallQuery(): string
    {
        return <<<'GRAPHQL'
        query EdgeFirewall($zoneTag: String!, $datetimeFrom: Time!, $datetimeTo: Time!) {
            viewer {
                zones(filter: { zoneTag: $zoneTag }) {
                    firewallEventsAdaptiveGroups(limit: 500, filter: { datetime_geq: $datetimeFrom, datetime_leq: $datetimeTo }, orderBy: [date_ASC]) {
                        dimensions { date action }
                        count
                    }
                }
            }
        }
        GRAPHQL;
    }

    private static function botsQuery(): string
    {
        return <<<'GRAPHQL'
        query EdgeBots($zoneTag: String!, $datetimeFrom: Time!, $datetimeTo: Time!) {
            viewer {
                zones(filter: { zoneTag: $zoneTag }) {
                    httpRequestsAdaptiveGroups(limit: 1000, filter: { datetime_geq: $datetimeFrom, datetime_leq: $datetimeTo }, orderBy: [date_ASC]) {
                        dimensions { date clientRequestUserAgent }
                        count
                    }
                }
            }
        }
        GRAPHQL;
    }
}
