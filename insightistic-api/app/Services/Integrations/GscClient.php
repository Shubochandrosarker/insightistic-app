<?php

namespace App\Services\Integrations;

use App\Models\DataSourceCredential;
use App\Models\ProviderAccount;
use App\Models\SeoDaily;
use App\Models\SeoPageDaily;
use App\Models\SeoPageSnapshot;
use App\Models\SeoQueryDaily;
use App\Models\SeoQueryPageDaily;
use App\Models\SeoQuerySnapshot;
use App\Models\Site;
use App\Support\SeoGrain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Google Search Console — property discovery plus a bounded pull into
 * `seo_daily`, `seo_query_snapshots` and `seo_page_snapshots`, and since
 * Phase 5 the daily-grain tables (`seo_query_daily`, `seo_page_daily`,
 * `seo_query_page_daily`) used by search intelligence.
 *
 * Query and page snapshots carry previous-period figures so the dashboard can
 * show movement without a second round-trip, and a coarse `opportunity` tag
 * (high impressions + low CTR) that the SEO page already knows how to render.
 * The daily-grain tables are UPSERT-written (doc 08): replaying any window
 * refreshes metrics in place instead of duplicating rows.
 */
class GscClient
{
    private const SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';
    private const QUERY_URL = 'https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';

    public function __construct(private readonly GoogleOAuthService $oauth)
    {
    }

    /**
     * Verified Search Console properties, for the picker.
     *
     * @return array<int,array{id:string,name:string,account:string}>
     */
    public function listResources(DataSourceCredential $credential): array
    {
        $token = $this->oauth->freshAccessToken($credential);

        $response = Http::withToken($token)
            ->timeout(config('insightistic.integrations.timeout'))
            ->get(self::SITES_URL);

        $this->assertOk($response, 'gsc_sites_failed');

        $out = [];
        foreach ($response->json()['siteEntry'] ?? [] as $entry) {
            $url = (string) ($entry['siteUrl'] ?? '');
            if ($url === '') {
                continue;
            }
            // Owners and full users can query; restricted levels cannot.
            if (($entry['permissionLevel'] ?? '') === 'siteUnverifiedUser') {
                continue;
            }
            $out[] = ['id' => $url, 'name' => $url, 'account' => (string) ($entry['permissionLevel'] ?? '')];
        }

        return $out;
    }

    /**
     * Verified Search Console properties for the ORG's shared Google grant
     * (spec §7) — the multi-select importer source. Includes permission
     * level so the UI can separate siteOwner/fullUser from restricted.
     *
     * @return array<int,array{id:string,name:string,account:string,permission_level:string}>
     */
    public function listResourcesForAccount(ProviderAccount $account): array
    {
        $token = $this->oauth->freshAccountToken($account);

        $response = Http::withToken($token)
            ->timeout(config('insightistic.integrations.timeout'))
            ->get(self::SITES_URL);

        $this->assertOk($response, 'gsc_sites_failed');

        $out = [];
        foreach ($response->json()['siteEntry'] ?? [] as $entry) {
            $url = (string) ($entry['siteUrl'] ?? '');
            $permission = (string) ($entry['permissionLevel'] ?? '');
            if ($url === '' || $permission === 'siteUnverifiedUser') {
                continue;
            }
            $out[] = [
                'id'               => $url,
                'name'             => $url,
                'account'          => $permission,
                'permission_level' => $permission,
            ];
        }

        return $out;
    }

    public function testConnection(DataSourceCredential $credential): array
    {
        $siteUrl = $this->siteUrl($credential);
        $token = $this->oauth->freshAccessToken($credential);

        $body = $this->query($token, $siteUrl, [
            'startDate'  => now()->subDays(7)->toDateString(),
            'endDate'    => now()->subDay()->toDateString(),
            'dimensions' => ['date'],
            'rowLimit'   => 1,
        ], 'gsc_test_failed');

        return ['site_url' => $siteUrl, 'rows' => count($body['rows'] ?? [])];
    }

    /**
     * @return array{days:int,queries:int,pages:int,latest_date:?string,daily_query_rows:int,daily_page_rows:int,daily_query_page_rows:int}
     */
    public function sync(Site $site, DataSourceCredential $credential, int $days = 30): array
    {
        $siteUrl = $this->siteUrl($credential);
        $token = $this->oauth->freshAccessToken($credential);

        // Search Console data lags ~2 days; asking for yesterday returns holes.
        $end = now()->subDays(3)->toDateString();
        $start = now()->subDays(3 + $days)->toDateString();
        $prevEnd = now()->subDays(4 + $days)->toDateString();
        $prevStart = now()->subDays(4 + ($days * 2))->toDateString();

        $latestDate = null;
        $dayRows = 0;

        $daily = $this->query($token, $siteUrl, [
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => ['date'],
            'rowLimit'   => 500,
        ], 'gsc_daily_failed');

        DB::transaction(function () use ($daily, $site, &$latestDate, &$dayRows) {
            foreach ($daily['rows'] ?? [] as $row) {
                $date = (string) ($row['keys'][0] ?? '');
                if ($date === '') {
                    continue;
                }

                SeoDaily::updateOrCreate(
                    // source is part of the unique key since Bing rows moved
                    // into this table — never match (and stomp) those.
                    ['site_id' => $site->id, 'source' => 'gsc', 'date' => $date],
                    [
                        'clicks'       => (int) ($row['clicks'] ?? 0),
                        'impressions'  => (int) ($row['impressions'] ?? 0),
                        'ctr'          => round((float) ($row['ctr'] ?? 0), 4),
                        'avg_position' => round((float) ($row['position'] ?? 0), 2),
                    ],
                );

                $dayRows++;
                if ($latestDate === null || $date > $latestDate) {
                    $latestDate = $date;
                }
            }
        });

        $queries = $this->syncDimension($site, $token, $siteUrl, 'query', $start, $end, $prevStart, $prevEnd);
        $pages = $this->syncDimension($site, $token, $siteUrl, 'page', $start, $end, $prevStart, $prevEnd);

        // Daily grain (Phase 5): one row per (date, query/page) per pull.
        // Idempotent upserts, pagination-aware — safe to replay any window.
        $dailyQueryRows = $this->fetchDailyWindow($site, $credential, $start, $end, 'queries');
        $dailyPageRows = $this->fetchDailyWindow($site, $credential, $start, $end, 'pages');
        $dailyQueryPageRows = $this->fetchDailyWindow($site, $credential, $start, $end, 'query_page');

        return [
            'days'        => $dayRows,
            'queries'     => $queries,
            'pages'       => $pages,
            'latest_date' => $latestDate,
            'daily_query_rows'      => $dailyQueryRows,
            'daily_page_rows'       => $dailyPageRows,
            'daily_query_page_rows' => $dailyQueryPageRows,
        ];
    }

    /** Shared query/page snapshot writer — same shape, different table. */
    private function syncDimension(
        Site $site,
        string $token,
        string $siteUrl,
        string $dimension,
        string $start,
        string $end,
        string $prevStart,
        string $prevEnd,
    ): int {
        $current = $this->query($token, $siteUrl, [
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => [$dimension],
            'rowLimit'   => 100,
        ], "gsc_{$dimension}_failed");

        $previous = $this->query($token, $siteUrl, [
            'startDate'  => $prevStart,
            'endDate'    => $prevEnd,
            'dimensions' => [$dimension],
            'rowLimit'   => 250,
        ], "gsc_{$dimension}_prev_failed");

        $prevByKey = [];
        foreach ($previous['rows'] ?? [] as $row) {
            $key = (string) ($row['keys'][0] ?? '');
            if ($key !== '') {
                $prevByKey[$key] = $row;
            }
        }

        $isQuery = $dimension === 'query';

        return DB::transaction(function () use ($current, $prevByKey, $site, $start, $end, $isQuery) {
            $model = $isQuery ? SeoQuerySnapshot::class : SeoPageSnapshot::class;

            $model::where('site_id', $site->id)
                ->where('period_start', $start)
                ->where('period_end', $end)
                ->delete();

            $written = 0;
            foreach ($current['rows'] ?? [] as $row) {
                $key = (string) ($row['keys'][0] ?? '');
                if ($key === '') {
                    continue;
                }

                $prev = $prevByKey[$key] ?? null;
                $impressions = (int) ($row['impressions'] ?? 0);
                $ctr = round((float) ($row['ctr'] ?? 0), 4);

                $attrs = [
                    'site_id'          => $site->id,
                    'period_start'     => $start,
                    'period_end'       => $end,
                    'clicks'           => (int) ($row['clicks'] ?? 0),
                    'impressions'      => $impressions,
                    'ctr'              => $ctr,
                    'position'         => round((float) ($row['position'] ?? 0), 2),
                    'prev_clicks'      => $prev ? (int) ($prev['clicks'] ?? 0) : null,
                    'prev_impressions' => $prev ? (int) ($prev['impressions'] ?? 0) : null,
                    'prev_ctr'         => $prev ? round((float) ($prev['ctr'] ?? 0), 4) : null,
                    'prev_position'    => $prev ? round((float) ($prev['position'] ?? 0), 2) : null,
                    'created_at'       => now(),
                ];

                if ($isQuery) {
                    $attrs['query'] = mb_substr($key, 0, 500);
                    // Plenty of demand, poor click-through — the classic
                    // "rewrite the title/meta" candidate.
                    $attrs['opportunity'] = ($impressions >= 100 && $ctr < 0.02) ? 'low_ctr_high_impressions' : null;
                } else {
                    $attrs['page_path'] = mb_substr($this->pathOf($key), 0, 1000);
                }

                $model::create($attrs);
                $written++;
            }

            return $written;
        });
    }

    /**
     * One date-window pull of one daily-grain dimension into the Phase 5
     * grain tables. Shared by sync() and the historical backfill job.
     *
     * Pagination-aware: GSC silently truncates at rowLimit per call, so a
     * FULL page is followed by another request and only a short page ends
     * the loop — data completeness beats saving a call. Writes are chunked
     * UPSERTs, so replaying the same window refreshes metrics in place.
     *
     * @param  'queries'|'pages'|'query_page'  $dimension
     * @return int Rows written (updated rows count too).
     */
    public function fetchDailyWindow(
        Site $site,
        DataSourceCredential $credential,
        string $from,
        string $to,
        string $dimension,
    ): int {
        $dimensions = match ($dimension) {
            'queries'    => ['date', 'query'],
            'pages'      => ['date', 'page'],
            'query_page' => ['date', 'query', 'page'],
            default      => throw new \InvalidArgumentException("Unknown SEO daily dimension '{$dimension}'."),
        };

        $siteUrl = $this->siteUrl($credential);
        $token = $this->oauth->freshAccessToken($credential);
        $limit = $this->rowLimit();

        $written = 0;
        $startRow = 0;

        do {
            $body = $this->query($token, $siteUrl, [
                'startDate'  => $from,
                'endDate'    => $to,
                'dimensions' => $dimensions,
                'rowLimit'   => $limit,
                'startRow'   => $startRow,
            ], "gsc_{$dimension}_daily_failed");

            $rows = $body['rows'] ?? [];
            $written += $this->writeDailyGrain($site, $dimension, $rows);
            $startRow += count($rows);
        } while (count($rows) === $limit);

        return $written;
    }

    /** Chunked UPSERT writer for the daily-grain tables (doc 08: replay-safe). */
    private function writeDailyGrain(Site $site, string $dimension, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = now();
        $written = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $payload = [];

            foreach ($chunk as $row) {
                $keys = $row['keys'] ?? [];
                $date = (string) ($keys[0] ?? '');
                if ($date === '') {
                    continue;
                }

                $metrics = [
                    'clicks'      => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'position'    => round((float) ($row['position'] ?? 0), 2),
                ];

                switch ($dimension) {
                    case 'queries':
                        $query = mb_substr((string) ($keys[1] ?? ''), 0, 500);
                        if ($query === '') {
                            continue 2;
                        }
                        // Hash the STORED (truncated) value, so a reader can
                        // rebuild the hash from the row itself.
                        $payload[] = $metrics + [
                            'site_id'    => $site->id,
                            'date'       => $date,
                            'query_hash' => SeoGrain::hash($query),
                            'query'      => $query,
                            'ctr'        => round((float) ($row['ctr'] ?? 0), 4),
                            'created_at' => $now,
                        ];
                        break;

                    case 'pages':
                        // Same normalization as the legacy page snapshots:
                        // path only — no scheme/host/query/fragment.
                        $path = mb_substr($this->pathOf((string) ($keys[1] ?? '')), 0, 1000);
                        if ($path === '') {
                            continue 2;
                        }
                        $payload[] = $metrics + [
                            'site_id'    => $site->id,
                            'date'       => $date,
                            'page_hash'  => SeoGrain::hash($path),
                            'page_path'  => $path,
                            'ctr'        => round((float) ($row['ctr'] ?? 0), 4),
                            'created_at' => $now,
                        ];
                        break;

                    case 'query_page':
                        $query = mb_substr((string) ($keys[1] ?? ''), 0, 500);
                        $path = mb_substr($this->pathOf((string) ($keys[2] ?? '')), 0, 1000);
                        if ($query === '' || $path === '') {
                            continue 2;
                        }
                        $payload[] = $metrics + [
                            'site_id'    => $site->id,
                            'date'       => $date,
                            'query_hash' => SeoGrain::hash($query),
                            'page_hash'  => SeoGrain::hash($path),
                            'created_at' => $now,
                        ];
                        break;
                }
            }

            if ($payload === []) {
                continue;
            }

            $update = ['clicks', 'impressions', 'position'];
            if ($dimension !== 'query_page') {
                $update[] = 'ctr';
            }

            $model = match ($dimension) {
                'queries'    => SeoQueryDaily::class,
                'pages'      => SeoPageDaily::class,
                'query_page' => SeoQueryPageDaily::class,
            };

            $unique = $dimension === 'query_page'
                ? ['site_id', 'date', 'query_hash', 'page_hash']
                : ['site_id', 'date', $dimension === 'queries' ? 'query_hash' : 'page_hash'];

            $model::upsert($payload, $unique, $update);
            $written += count($payload);
        }

        return $written;
    }

    /** GSC's hard per-call row cap; config only exists so tests can shrink it. */
    private function rowLimit(): int
    {
        return max(1, (int) config('insightistic.integrations.gsc_row_limit', 25000));
    }

    private function query(string $token, string $siteUrl, array $body, string $errorCode): array
    {
        $response = Http::withToken($token)
            ->timeout(config('insightistic.integrations.timeout'))
            ->post(sprintf(self::QUERY_URL, rawurlencode($siteUrl)), $body);

        $this->assertOk($response, $errorCode);

        return $response->json() ?? [];
    }

    /** Store a normalised path so pages join cleanly with connector data. */
    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $url;
    }

    private function siteUrl(DataSourceCredential $credential): string
    {
        $id = $credential->resource_json['id'] ?? null;

        if (! $id) {
            throw new IntegrationFailure('no_property_selected', 'Choose a Search Console property for this site first.');
        }

        return (string) $id;
    }

    private function assertOk($response, string $errorCode): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json()['error']['message'] ?? 'Search Console request failed.');

        throw new IntegrationFailure(
            in_array($response->status(), [401, 403], true) ? 'reauth_required' : $errorCode,
            $message,
            retryable: $response->status() === 429 || $response->status() >= 500,
        );
    }
}
