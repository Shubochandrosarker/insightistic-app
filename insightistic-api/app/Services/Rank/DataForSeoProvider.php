<?php

namespace App\Services\Rank;

use App\Models\RankKeyword;
use App\Models\Site;
use App\Services\Integrations\IntegrationFailure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * DataForSEO SERP provider (Phase 10, doc 13). One implementation of
 * RankProvider: POSTs a live Google organic SERP task per keyword to
 * /v3/serp/google/organic/live/regular with HTTP Basic auth
 * (DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD via config('services.dataforseo')).
 *
 * Batching: the live regular endpoint accepts a TASKS ARRAY — one task per
 * keyword inside a single POST, capped here at MAX_TASKS_PER_CALL = 100
 * (DataForSEO's documented per-call limit). Bigger batches are simply split
 * into more POSTs.
 *
 * Location handling — deliberately name-based, not location_code-based:
 * DataForSEO location_code numbers are cryptic (2840 = United States) and
 * easy to get wrong, so we send `location_name` resolved from a tiny,
 * deliberately extensible COUNTRY_NAMES map for the ISO-2 codes we support
 * today (us, in, gb, au, ca, de) plus `language_name` => 'English'. An ISO-2
 * code missing from the map (or a NULL country) omits the location and
 * inherits DataForSEO's default market (United States). A keyword's free-text
 * `location` column (e.g. "New York") wins over the country map because it
 * is the most specific request the user made.
 *
 * Result semantics (mirrors RankProvider's contract): position is the first
 * ORGANIC item whose host matches the tracked site's domain; items of other
 * types (featured_snippet, paid, local_pack, ...) are collected as
 * serp_features. No matching organic item inside the top 100 → position null
 * (tracked but not ranking). Task-level provider errors (status_code >=
 * 40000) throw — a failed call never turns into fake "not found" rows.
 *
 * Credentials are Basic-auth headers only and are NEVER echoed into error
 * messages (same rule as the Bing client).
 */
class DataForSeoProvider implements RankProvider
{
    public const PROVIDER_NAME = 'dataforseo';

    private const ENDPOINT = 'https://api.dataforseo.com/v3/serp/google/organic/live/regular';

    /** DataForSEO hard per-call task cap. */
    public const MAX_TASKS_PER_CALL = 100;

    /** ISO-2 → DataForSEO location_name. Extensible: add a line, done. */
    public const COUNTRY_NAMES = [
        'us' => 'United States',
        'in' => 'India',
        'gb' => 'United Kingdom',
        'au' => 'Australia',
        'ca' => 'Canada',
        'de' => 'Germany',
    ];

    /** ISO-2-ish language code → DataForSEO language_name. */
    public const LANGUAGE_NAMES = [
        'en' => 'English',
    ];

    /** Envelope status_code meaning "Ok" in the DataForSEO v3 envelope. */
    private const STATUS_OK = 10000;

    public function available(): bool
    {
        $config = (array) config('services.dataforseo');

        return filled($config['login'] ?? null) && filled($config['password'] ?? null);
    }

    public function checkBatch(array $keywords): array
    {
        $keywords = array_values($keywords);

        if ($keywords === []) {
            return [];
        }

        $sites = Site::withoutGlobalScope('organization')
            ->whereIn('id', collect($keywords)->pluck('site_id')->unique())
            ->get()
            ->keyBy('id');

        $results = [];

        foreach (array_chunk($keywords, self::MAX_TASKS_PER_CALL) as $chunk) {
            $results += $this->runChunk($chunk, $sites);
        }

        return $results;
    }

    /**
     * One POST: up to MAX_TASKS_PER_CALL tasks, one per keyword.
     *
     * @param  list<RankKeyword>  $chunk
     * @param  Collection<int, Site>  $sites
     * @return array<int, array{position: ?int, ranking_url: ?string, serp_features: ?array, provider_request_id: ?string, cost_units: float}>
     */
    private function runChunk(array $chunk, Collection $sites): array
    {
        [$tasks, $expected] = $this->buildTasks($chunk, $sites);

        $response = Http::withBasicAuth($this->login(), $this->password())
            ->timeout((int) config('insightistic.integrations.timeout', 20))
            ->asJson()
            ->post(self::ENDPOINT, $tasks);

        if (! $response->successful()) {
            throw new IntegrationFailure(
                'rank_provider_failed',
                sprintf('DataForSEO request failed (HTTP %d).', $response->status()),
                retryable: $response->status() === 429 || $response->status() >= 500,
            );
        }

        $envelope = $response->json() ?? [];

        if ((int) ($envelope['status_code'] ?? 0) !== self::STATUS_OK) {
            throw new IntegrationFailure(
                'rank_provider_failed',
                sprintf('DataForSEO rejected the batch: %s.', $envelope['status_message'] ?? 'unknown error'),
                retryable: false,
            );
        }

        // Distribute the batch's billable cost evenly across its keywords
        // (DataForSEO reports cost per task; per-keyword attribution beyond
        // "even share" is not worth the coupling to their pricing model).
        $totalCost = 0.0;
        foreach ($envelope['tasks'] ?? [] as $task) {
            $totalCost += (float) ($task['cost'] ?? 0);
        }
        $perKeyword = round($totalCost / max(1, count($chunk)), 4);

        // Response tasks come back in submission order; duplicate keyword
        // TEXTS (same phrase, different market) are matched FIFO per text so
        // every task lands on its own keyword row.
        $queues = [];
        $sitesByKeywordId = [];
        foreach ($expected as $expectedKeyword) {
            $queues[$expectedKeyword['text']][] = $expectedKeyword['id'];
            $sitesByKeywordId[$expectedKeyword['id']] = $sites->get($expectedKeyword['site_id']);
        }

        $results = [];

        foreach ($envelope['tasks'] ?? [] as $task) {
            $text = mb_strtolower(trim((string) ($task['data']['keyword'] ?? '')));
            $keywordId = empty($queues[$text]) ? null : array_shift($queues[$text]);

            if ($keywordId === null) {
                continue; // A task for a keyword we never sent — ignore it.
            }

            // Hard task errors (bad location, insufficient funds, ...) must
            // NOT be recorded as "not ranking" observations — that would
            // trigger false rank_not_found alerts. Fail the whole batch.
            $taskStatus = (int) ($task['status_code'] ?? 0);
            if ($taskStatus >= 40000) {
                throw new IntegrationFailure(
                    'rank_provider_failed',
                    sprintf('DataForSEO task failed: %s.', $task['status_message'] ?? 'unknown error'),
                    retryable: $taskStatus >= 40600,
                );
            }

            $items = $task['result'][0]['items'] ?? [];

            // Position and ranking_url come from the same matched organic
            // item (the tracked site's own listing); no match = not found.
            $matched = $this->matchOrganicItem($items, $sitesByKeywordId[$keywordId] ?? null);

            $results[$keywordId] = [
                'position'            => $matched['rank'] ?? null,
                'ranking_url'         => $matched['url'] ?? null,
                'serp_features'       => $this->featuresOf($items),
                'provider_request_id' => isset($task['id']) ? mb_substr((string) $task['id'], 0, 64) : null,
                'cost_units'          => $perKeyword,
            ];
        }

        // Keywords whose task never came back count against cost but have no
        // observation — return them as explicit not-found so RankChecker's
        // accounting (last_checked_at) stays consistent with the call made.
        foreach ($expected as $expectedKeyword) {
            if (! isset($results[$expectedKeyword['id']])) {
                $results[$expectedKeyword['id']] = [
                    'position'            => null,
                    'ranking_url'         => null,
                    'serp_features'       => null,
                    'provider_request_id' => null,
                    'cost_units'          => $perKeyword,
                ];
            }
        }

        return $results;
    }

    /**
     * Build the POST body (one task per keyword) plus the expected
     * keyword-id ↔ keyword-text mapping used to read the response back.
     *
     * @param  list<RankKeyword>  $chunk
     * @return array{0: list<array<string, mixed>>, 1: list<array{id: int, text: string, site_id: int}>}
     */
    private function buildTasks(array $chunk, Collection $sites): array
    {
        $tasks = [];
        $expected = [];

        foreach ($chunk as $keyword) {
            $site = $sites->get($keyword->site_id);
            $text = trim((string) $keyword->keyword);

            $task = ['keyword' => $text];

            $locationName = $this->locationNameFor($keyword);
            if ($locationName !== null) {
                $task['location_name'] = $locationName;
            }

            $languageName = $this->languageNameFor($keyword);
            if ($languageName !== null) {
                $task['language_name'] = $languageName;
            }

            $tasks[] = $task;
            $expected[] = [
                'id'      => $keyword->id,
                'text'    => mb_strtolower($text),
                'site_id' => (int) $keyword->site_id,
            ];
        }

        return [$tasks, $expected];
    }

    /** location column (free text) → country map → null (provider default = US). */
    private function locationNameFor(RankKeyword $keyword): ?string
    {
        $location = trim((string) $keyword->location);
        if ($location !== '') {
            return $location;
        }

        $country = mb_strtolower(trim((string) $keyword->country));

        return self::COUNTRY_NAMES[$country] ?? null;
    }

    private function languageNameFor(RankKeyword $keyword): ?string
    {
        $language = mb_strtolower(trim((string) $keyword->language));

        return self::LANGUAGE_NAMES[$language] ?? null;
    }

    /**
     * First organic item belonging to the tracked site's own domain.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{rank: int, url: ?string}|null  null = no site listing inside the top 100
     */
    private function matchOrganicItem(array $items, ?Site $site): ?array
    {
        if ($site === null) {
            return null;
        }

        $domain = self::normalizeHost($site->domain);
        if ($domain === null) {
            return null;
        }

        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'organic') {
                continue;
            }

            $itemHost = self::normalizeHost(
                (string) ($item['domain'] ?? parse_url((string) ($item['url'] ?? ''), PHP_URL_HOST) ?? ''),
            );

            if ($itemHost !== null && str_ends_with($itemHost, $domain)) {
                $rank = (int) ($item['rank_absolute'] ?? $item['rank_position'] ?? 0);

                // Items are ordered by rank: the site's best listing decides.
                // Outside the observable top 100 (or bogus rank) = not found.
                if ($rank < 1 || $rank > 100) {
                    return null;
                }

                return [
                    'rank' => $rank,
                    'url'  => isset($item['url']) ? mb_substr((string) $item['url'], 0, 1000) : null,
                ];
            }
        }

        return null;
    }

    /** SERP feature types other than plain organic (featured_snippet, paid, ...). */
    private function featuresOf(array $items): ?array
    {
        $features = [];

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type !== '' && $type !== 'organic') {
                $features[$type] = true;
            }
        }

        return $features === [] ? null : array_keys($features);
    }

    /** "www.sub.example.com" → "sub.example.com"; accepts raw host or full URL. */
    private static function normalizeHost(?string $hostOrUrl): ?string
    {
        $value = trim((string) $hostOrUrl);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $value = (string) (parse_url($value, PHP_URL_HOST) ?: '');
        }

        $value = mb_strtolower(rtrim($value, '/'));

        return str_starts_with($value, 'www.') ? mb_substr($value, 4) : $value;
    }

    private function login(): string
    {
        return (string) config('services.dataforseo.login');
    }

    private function password(): string
    {
        return (string) config('services.dataforseo.password');
    }
}
