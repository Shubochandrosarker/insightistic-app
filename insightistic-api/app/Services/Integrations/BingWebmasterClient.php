<?php

namespace App\Services\Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Bing Webmaster Tools API client (roadmap 11-BING-WEBMASTER-INDEXNOW).
 *
 * Bing authenticates with a plain API key passed as a query parameter —
 * there is no OAuth dance. That key is the credential secret: it is only
 * ever sent to ssl.bing.com and must never appear in an exception message,
 * log line or API response (same rule as every other integration here).
 *
 * Endpoint shapes used:
 *  - GetUserSites            → {"d":[{"Url":"https://example.com/","Title":"…"}]}
 *  - GetRankAndTrafficStats  → {"d":[{"Date":"\/Date(1399100400000)\/","Clicks":n,"Impressions":n}]}
 *    — DAILY totals across all queries.
 *  - GetQueryStats           → {"d":[{"Query":"q","Clicks":n,"Impressions":n,
 *                               "AvgClickPosition":n,"AvgImpressionPosition":n,"Date":"\/Date(ms)\/"}]}
 *    — WEEKLY buckets; the Date is the bucket's end (one entry per Saturday
 *    covering the 7 days ending that Friday, Pacific time). No date-range
 *    parameter exists: both endpoints return everything the account has.
 */
class BingWebmasterClient
{
    private const API_BASE = 'https://ssl.bing.com/webmaster/api.svc/json';

    /**
     * Sites the API key can see, for the picker.
     *
     * @return array<int,array{id:string,name:string,account:string}>
     */
    public function sites(string $apiKey): array
    {
        $body = $this->call('GetUserSites', $apiKey, [], 'bing_sites_failed');

        $out = [];
        foreach ($body['d'] ?? [] as $entry) {
            $url = rtrim((string) ($entry['Url'] ?? ''), '/');
            if ($url === '') {
                continue;
            }

            $out[] = [
                'id'      => $url,
                'name'    => (string) ($entry['Title'] ?? '') ?: $url,
                'account' => (string) ($entry['Username'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Daily click/impression totals, ascending by date.
     *
     * @return array<int,array{date:CarbonImmutable,clicks:int,impressions:int}>
     */
    public function dailyTraffic(string $apiKey, string $siteUrl): array
    {
        $body = $this->call('GetRankAndTrafficStats', $apiKey, ['siteUrl' => $siteUrl], 'bing_daily_failed');

        $out = [];
        foreach ($body['d'] ?? [] as $row) {
            $date = $this->bingDate($row['Date'] ?? null);

            if ($date === null) {
                continue; // Unparseable bucket — skip rather than guess.
            }

            $out[] = [
                'date'        => $date,
                'clicks'      => (int) ($row['Clicks'] ?? 0),
                'impressions' => (int) ($row['Impressions'] ?? 0),
            ];
        }

        usort($out, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $out;
    }

    /**
     * Weekly query buckets (week_end = the bucket's end date).
     *
     * @return array<int,array{week_end:CarbonImmutable,query:string,clicks:int,impressions:int,avg_click_position:?float,avg_impression_position:?float}>
     */
    public function weeklyQueries(string $apiKey, string $siteUrl): array
    {
        $body = $this->call('GetQueryStats', $apiKey, ['siteUrl' => $siteUrl], 'bing_queries_failed');

        $out = [];
        foreach ($body['d'] ?? [] as $row) {
            $weekEnd = $this->bingDate($row['Date'] ?? null);
            $query = trim((string) ($row['Query'] ?? ''));

            if ($weekEnd === null || $query === '') {
                continue;
            }

            $out[] = [
                'week_end'                => $weekEnd,
                'query'                   => $query,
                'clicks'                  => (int) ($row['Clicks'] ?? 0),
                'impressions'             => (int) ($row['Impressions'] ?? 0),
                'avg_click_position'      => isset($row['AvgClickPosition']) ? (float) $row['AvgClickPosition'] : null,
                'avg_impression_position' => isset($row['AvgImpressionPosition']) ? (float) $row['AvgImpressionPosition'] : null,
            ];
        }

        return $out;
    }

    /**
     * Bing's WCF-style epoch timestamp: "/Date(1399100400000)/".
     *
     * @return CarbonImmutable|null null when the value is absent or garbage —
     *                              callers skip the row instead of guessing.
     */
    private function bingDate(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw) || ! preg_match('/-?\d+/', $raw, $m)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestampMs((int) $m[0], 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function call(string $method, string $apiKey, array $query, string $errorCode): array
    {
        $response = Http::timeout(config('insightistic.integrations.timeout'))
            ->get(self::API_BASE . '/' . $method, array_filter(['apikey' => $apiKey] + $query));

        if (! $response->successful()) {
            // Bing's error body is {"ErrorCode":n,"Message":"…"} — surface the
            // provider's message only; the apikey lives in the URL and must
            // never travel into an exception or a log.
            $message = (string) ($response->json('Message') ?? 'Bing Webmaster request failed.');

            throw new IntegrationFailure(
                $errorCode,
                $message,
                retryable: $response->status() === 429 || $response->status() >= 500,
            );
        }

        return $response->json() ?? [];
    }
}
