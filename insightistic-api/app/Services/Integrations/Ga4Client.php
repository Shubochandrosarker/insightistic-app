<?php

namespace App\Services\Integrations;

use App\Models\DataSourceCredential;
use App\Models\ProviderAccount;
use App\Models\Site;
use App\Models\TrafficDaily;
use App\Models\TrafficDimensionSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Google Analytics 4 — property discovery plus a bounded daily pull into
 * `traffic_daily` / `traffic_dimension_snapshots`.
 *
 * Rows are written with source='ga4' so GA4 numbers never collide with the
 * connector's own `connector_engagement` rows; both can coexist for a site
 * and the dashboard picks whichever source it is showing.
 */
class Ga4Client
{
    private const ADMIN_URL = 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries';
    private const DATA_URL = 'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport';

    public function __construct(private readonly GoogleOAuthService $oauth)
    {
    }

    /**
     * GA4 properties the granting account can read, for the picker.
     *
     * @return array<int,array{id:string,name:string,account:string}>
     */
    public function listResources(DataSourceCredential $credential): array
    {
        $token = $this->oauth->freshAccessToken($credential);

        $response = Http::withToken($token)
            ->timeout(config('insightistic.integrations.timeout'))
            ->get(self::ADMIN_URL, ['pageSize' => 200]);

        $this->assertOk($response, 'ga4_properties_failed');

        $out = [];
        foreach ($response->json()['accountSummaries'] ?? [] as $account) {
            foreach ($account['propertySummaries'] ?? [] as $property) {
                // "properties/123456" -> "123456"
                $id = str_replace('properties/', '', (string) ($property['property'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $out[] = [
                    'id'      => $id,
                    'name'    => (string) ($property['displayName'] ?? $id),
                    'account' => (string) ($account['displayName'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /** GA4 properties visible to the ORG's shared Google grant (spec §5). */
    public function listResourcesForAccount(ProviderAccount $account): array
    {
        $token = $this->oauth->freshAccountToken($account);

        $response = Http::withToken($token)
            ->timeout(config('insightistic.integrations.timeout'))
            ->get(self::ADMIN_URL, ['pageSize' => 200]);

        $this->assertOk($response, 'ga4_properties_failed');

        $out = [];
        foreach ($response->json()['accountSummaries'] ?? [] as $acct) {
            foreach ($acct['propertySummaries'] ?? [] as $property) {
                $id = str_replace('properties/', '', (string) ($property['property'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $out[] = [
                    'id'      => $id,
                    'name'    => (string) ($property['displayName'] ?? $id),
                    'account' => (string) ($acct['displayName'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /** Cheap read that proves the grant and the selected property both work. */
    public function testConnection(DataSourceCredential $credential): array
    {
        $propertyId = $this->propertyId($credential);
        $token = $this->oauth->freshAccessToken($credential);

        $response = Http::withToken($token)
            ->timeout(config('insightistic.integrations.timeout'))
            ->post(sprintf(self::DATA_URL, $propertyId), [
                'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'yesterday']],
                'metrics'    => [['name' => 'sessions']],
                'limit'      => 1,
            ]);

        $this->assertOk($response, 'ga4_test_failed');

        return [
            'property_id' => $propertyId,
            'rows'        => (int) ($response->json()['rowCount'] ?? 0),
        ];
    }

    /**
     * Pull the last $days of daily totals and top landing pages.
     *
     * @return array{days:int,dimension_rows:int,latest_date:?string}
     */
    public function sync(Site $site, DataSourceCredential $credential, int $days = 30): array
    {
        $propertyId = $this->propertyId($credential);
        $token = $this->oauth->freshAccessToken($credential);
        $start = now()->subDays($days)->toDateString();
        $end = now()->subDay()->toDateString();

        $daily = $this->runReport($token, $propertyId, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'date']],
            'metrics'    => array_map(fn ($m) => ['name' => $m], [
                'totalUsers', 'newUsers', 'sessions', 'engagedSessions',
                'screenPageViews', 'engagementRate', 'bounceRate',
                'averageSessionDuration', 'conversions', 'transactions', 'totalRevenue',
            ]),
            'limit' => 400,
        ], 'ga4_daily_failed');

        $latestDate = null;
        $rows = 0;

        DB::transaction(function () use ($daily, $site, &$latestDate, &$rows) {
            foreach ($daily['rows'] ?? [] as $row) {
                $raw = $row['dimensionValues'][0]['value'] ?? '';   // YYYYMMDD
                if (! preg_match('/^\d{8}$/', $raw)) {
                    continue;
                }
                $date = substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
                $m = array_map(fn ($v) => $v['value'] ?? 0, $row['metricValues'] ?? []);

                TrafficDaily::updateOrCreate(
                    ['site_id' => $site->id, 'source' => 'ga4', 'date' => $date],
                    [
                        'users'            => (int) ($m[0] ?? 0),
                        'new_users'        => (int) ($m[1] ?? 0),
                        'sessions'         => (int) ($m[2] ?? 0),
                        'engaged_sessions' => (int) ($m[3] ?? 0),
                        'page_views'       => (int) ($m[4] ?? 0),
                        // GA4 returns these as 0..1 ratios; the column is a percent.
                        'engagement_rate'  => round(((float) ($m[5] ?? 0)) * 100, 2),
                        'bounce_rate'      => round(((float) ($m[6] ?? 0)) * 100, 2),
                        'avg_engagement_duration_seconds' => (int) round((float) ($m[7] ?? 0)),
                        'conversions'      => (int) ($m[8] ?? 0),
                        'transactions'     => (int) ($m[9] ?? 0),
                        'revenue'          => round((float) ($m[10] ?? 0), 2),
                    ],
                );

                $rows++;
                if ($latestDate === null || $date > $latestDate) {
                    $latestDate = $date;
                }
            }
        });

        $dimensionRows = 0;
        foreach (self::BREAKDOWN_DIMENSIONS as $type => $ga4Dimension) {
            $dimensionRows += $this->syncDimension($site, $token, $propertyId, $start, $end, $type, $ga4Dimension);
        }

        return ['days' => $rows, 'dimension_rows' => $dimensionRows, 'latest_date' => $latestDate];
    }

    /**
     * Dashboard breakdown tab => GA4 Data API dimension. Every tab on the
     * Traffic page reads `traffic_dimension_snapshots` by dimension_type, so
     * each one must be synced here or its tab stays empty.
     */
    public const BREAKDOWN_DIMENSIONS = [
        'channel'       => 'sessionDefaultChannelGroup',
        'source_medium' => 'sessionSourceMedium',
        'country'       => 'country',
        'device'        => 'deviceCategory',
        'landing_page'  => 'landingPagePlusQueryString',
    ];

    /** Top rows of one breakdown dimension for the window, replacing the previous snapshot. */
    private function syncDimension(Site $site, string $token, string $propertyId, string $start, string $end, string $type, string $ga4Dimension): int
    {
        $report = $this->runReport($token, $propertyId, [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => $ga4Dimension]],
            'metrics'    => array_map(fn ($m) => ['name' => $m], ['sessions', 'totalUsers', 'screenPageViews', 'conversions', 'totalRevenue']),
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit'      => 50,
        ], $type === 'landing_page' ? 'ga4_pages_failed' : 'ga4_dimensions_failed');

        return DB::transaction(function () use ($report, $site, $start, $end, $type) {
            // Snapshots are point-in-time for a window; clear the window first
            // so a re-sync replaces rather than duplicates.
            TrafficDimensionSnapshot::where('site_id', $site->id)
                ->where('source', 'ga4')
                ->where('dimension_type', $type)
                ->where('period_start', $start)
                ->where('period_end', $end)
                ->delete();

            $rank = 0;
            foreach ($report['rows'] ?? [] as $row) {
                $value = (string) ($row['dimensionValues'][0]['value'] ?? '');
                if ($value === '') {
                    continue;
                }
                $m = array_map(fn ($v) => $v['value'] ?? 0, $row['metricValues'] ?? []);
                $rank++;

                TrafficDimensionSnapshot::create([
                    'site_id'         => $site->id,
                    'period_start'    => $start,
                    'period_end'      => $end,
                    'source'          => 'ga4',
                    'dimension_type'  => $type,
                    'dimension_value' => mb_substr($value, 0, 255),
                    'url'             => $type === 'landing_page' ? mb_substr($value, 0, 255) : null,
                    'sessions'        => (int) ($m[0] ?? 0),
                    'users'           => (int) ($m[1] ?? 0),
                    'views'           => (int) ($m[2] ?? 0),
                    'conversions'     => (int) ($m[3] ?? 0),
                    'revenue'         => round((float) ($m[4] ?? 0), 2),
                    'rank'            => $rank,
                    'created_at'      => now(),
                ]);
            }

            return $rank;
        });
    }

    private function runReport(string $token, string $propertyId, array $body, string $errorCode): array
    {
        $response = Http::withToken($token)
            ->timeout(config('insightistic.integrations.timeout'))
            ->post(sprintf(self::DATA_URL, $propertyId), $body);

        $this->assertOk($response, $errorCode);

        return $response->json() ?? [];
    }

    private function propertyId(DataSourceCredential $credential): string
    {
        $id = $credential->resource_json['id'] ?? null;

        if (! $id) {
            throw new IntegrationFailure('no_property_selected', 'Choose a GA4 property for this site first.');
        }

        return (string) $id;
    }

    private function assertOk($response, string $errorCode): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json()['error']['message'] ?? 'Google Analytics request failed.');

        // 401/403 mean the grant is gone or lacks the property; retrying will
        // not help, so mark it terminal and make the user reconnect.
        throw new IntegrationFailure(
            in_array($response->status(), [401, 403], true) ? 'reauth_required' : $errorCode,
            $message,
            retryable: $response->status() === 429 || $response->status() >= 500,
        );
    }
}
