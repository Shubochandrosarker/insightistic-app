<?php

namespace App\Services\Rank;

use App\Models\RankKeyword;
use App\Models\Site;
use App\Services\Integrations\IntegrationFailure;
use Illuminate\Support\Collection;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DataForSEO SERP provider: the default RankProvider (RANK_PROVIDER=dataforseo).
 *
 * Live Google Organic endpoint, /v3/serp/google/organic/live/regular, with
 * HTTP Basic auth (DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD).
 *
 * Request rules, per DataForSEO's v3 docs (checked 2026-11-11):
 * - **One task per POST.** Live calls accept a single task each. Keywords are
 *   sent as parallel requests (Http::pool, DATAFORSEO_CONCURRENCY at a time,
 *   far below DataForSEO's per-minute call limit).
 * - **depth.** DataForSEO's default depth is 10 results. We send
 *   DATAFORSEO_DEPTH (default 100) so "not found" really means "not in the
 *   top 100". Billing is per 10 results, rounded up: depth 100 = 10 SERPs.
 *   Pages Google doesn't return are refunded by DataForSEO automatically.
 * - **device.** desktop|mobile comes from the keyword. Mobile keywords used
 *   to be checked as desktop.
 * - **tag.** Set to the rank_keywords id, so each answer maps back to its
 *   keyword exactly. Duplicate phrases in different markets can't collide.
 * - **Status codes.** 20000 = Ok, both on the envelope and on each task.
 *   40000+ = error.
 *
 * Location: a free-text `location` on the keyword wins. Otherwise the ISO-2
 * country goes through COUNTRY_NAMES. If neither is set, DataForSEO's default
 * market (United States) applies.
 *
 * Results: position = the first ORGANIC item whose host matches the tracked
 * site (rank_absolute, 1..depth). Other item types are collected as
 * serp_features. cost_units = the task's own reported cost in USD (measured,
 * not estimated).
 *
 * Failures:
 * - Any failed keyword is left out of the result. RankChecker then writes no
 *   observation for it, so it stays due.
 * - If every keyword fails, an IntegrationFailure is thrown.
 * - Partial failures are logged once per batch.
 * - A failed call never becomes a fake "not ranking" row.
 * - Credentials are never echoed into errors or logs.
 */
class DataForSeoProvider implements RankProvider
{
    public const PROVIDER_NAME = 'dataforseo';

    public const ENDPOINT = 'https://api.dataforseo.com/v3/serp/google/organic/live/regular';

    public const USER_DATA_ENDPOINT = 'https://api.dataforseo.com/v3/appendix/user_data';

    /** Published Live price per SERP (10 results), USD, used ONLY for pre-flight estimates. */
    public const LIVE_PRICE_PER_SERP_USD = 0.002;

    public const DEFAULT_DEPTH = 100;

    public const MAX_DEPTH = 200;

    /** ISO-2 → DataForSEO location_name. Extensible: add a line, done. */
    public const COUNTRY_NAMES = [
        'us' => 'United States',
        'in' => 'India',
        'gb' => 'United Kingdom',
        'au' => 'Australia',
        'ca' => 'Canada',
        'de' => 'Germany',
        'bd' => 'Bangladesh',
        'fr' => 'France',
        'es' => 'Spain',
        'nl' => 'Netherlands',
    ];

    /** ISO-2-ish language code → DataForSEO language_name. */
    public const LANGUAGE_NAMES = [
        'en' => 'English',
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
        'nl' => 'Dutch',
        'bn' => 'Bengali',
        'hi' => 'Hindi',
    ];

    /** DataForSEO v3 "Ok." (envelope and task level). */
    public const STATUS_OK = 20000;

    public function name(): string
    {
        return self::PROVIDER_NAME;
    }

    public function available(): bool
    {
        $config = (array) config('services.dataforseo');

        return filled($config['login'] ?? null) && filled($config['password'] ?? null);
    }

    public function depth(): int
    {
        $depth = (int) config('services.dataforseo.depth', self::DEFAULT_DEPTH);

        return max(10, min(self::MAX_DEPTH, $depth ?: self::DEFAULT_DEPTH));
    }

    /** SERPs billed per keyword check (depth rounded up to the next ten). */
    public function serpsPerCheck(): int
    {
        return (int) ceil($this->depth() / 10);
    }

    /** Published-price estimate for one keyword check (pre-flight only; real cost is measured). */
    public function estimatedCostPerCheckUsd(): float
    {
        return round($this->serpsPerCheck() * self::LIVE_PRICE_PER_SERP_USD, 4);
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

        $concurrency = max(1, min(20, (int) config('services.dataforseo.concurrency', 5)));

        $results = [];
        $failures = [];

        foreach (array_chunk($keywords, $concurrency) as $chunk) {
            [$ok, $failed] = $this->runConcurrent($chunk, $sites);
            $results += $ok;
            $failures = array_merge($failures, $failed);
        }

        if ($results === [] && $failures !== []) {
            // Nothing usable: surface the first failure (retryable if any was).
            $retryable = collect($failures)->contains(fn ($f) => $f->retryable);
            throw new IntegrationFailure($failures[0]->errorCode, $failures[0]->getMessage(), retryable: $retryable);
        }

        if ($failures !== []) {
            Log::warning('rank.dataforseo.partial_failure', [
                'failed'  => count($failures),
                'ok'      => count($results),
                'reasons' => collect($failures)->map(fn ($f) => $f->getMessage())->countBy()->all(),
            ]);
        }

        return $results;
    }

    /**
     * Credential + balance probe (free endpoint). Never returns credentials.
     *
     * @return array{ok: bool, balance_usd: ?float, error: ?string}
     */
    public function userData(): array
    {
        if (! $this->available()) {
            return ['ok' => false, 'balance_usd' => null, 'error' => 'not_configured'];
        }

        try {
            $response = Http::withBasicAuth($this->login(), $this->password())
                ->timeout($this->timeout())
                ->acceptJson()
                ->get(self::USER_DATA_ENDPOINT);
        } catch (\Throwable $e) {
            return ['ok' => false, 'balance_usd' => null, 'error' => 'connection_failed'];
        }

        $body = $response->json() ?? [];
        if (! $response->successful() || (int) ($body['status_code'] ?? 0) !== self::STATUS_OK) {
            return ['ok' => false, 'balance_usd' => null, 'error' => sprintf('http_%d_status_%s', $response->status(), $body['status_code'] ?? 'none')];
        }

        $balance = $body['tasks'][0]['result'][0]['money']['balance'] ?? null;

        return ['ok' => true, 'balance_usd' => $balance !== null ? (float) $balance : null, 'error' => null];
    }

    /**
     * One live POST per keyword, sent in parallel.
     *
     * @param  list<RankKeyword>  $chunk
     * @param  Collection<int, Site>  $sites
     * @return array{0: array<int, array>, 1: list<IntegrationFailure>}
     */
    private function runConcurrent(array $chunk, Collection $sites): array
    {
        $login = $this->login();
        $password = $this->password();
        $timeout = $this->timeout();

        $responses = Http::pool(function (Pool $pool) use ($chunk, $login, $password, $timeout) {
            $requests = [];
            foreach ($chunk as $keyword) {
                $requests[] = $pool->as((string) $keyword->id)
                    ->withBasicAuth($login, $password)
                    ->timeout($timeout)
                    ->asJson()
                    ->post(self::ENDPOINT, [$this->buildTask($keyword)]);
            }

            return $requests;
        });

        $ok = [];
        $failed = [];

        foreach ($chunk as $keyword) {
            $response = $responses[(string) $keyword->id] ?? null;

            try {
                $ok[$keyword->id] = $this->parseResponse($response, $keyword, $sites->get($keyword->site_id));
            } catch (IntegrationFailure $e) {
                $failed[] = $e;
            }
        }

        return [$ok, $failed];
    }

    /** @return array{position: ?int, ranking_url: ?string, serp_features: ?array, provider_request_id: ?string, cost_units: float} */
    private function parseResponse(mixed $response, RankKeyword $keyword, ?Site $site): array
    {
        if (! $response instanceof Response) {
            // Connection error / timeout inside the pool.
            throw new IntegrationFailure('rank_provider_failed', 'DataForSEO request failed (connection error).', retryable: true);
        }

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
                sprintf('DataForSEO rejected the request: %s.', $envelope['status_message'] ?? 'unknown error'),
                retryable: false,
            );
        }

        $task = collect($envelope['tasks'] ?? [])
            ->first(fn ($t) => (string) ($t['data']['tag'] ?? '') === (string) $keyword->id)
            ?? (($envelope['tasks'] ?? [])[0] ?? null);

        if (! is_array($task)) {
            throw new IntegrationFailure('rank_provider_failed', 'DataForSEO returned no task.', retryable: true);
        }

        // Hard task errors (bad location, insufficient funds, ...) must NOT
        // be recorded as "not ranking" observations.
        $taskStatus = (int) ($task['status_code'] ?? 0);
        if ($taskStatus !== self::STATUS_OK) {
            throw new IntegrationFailure(
                'rank_provider_failed',
                sprintf('DataForSEO task failed: %s.', $task['status_message'] ?? 'unknown error'),
                retryable: $taskStatus >= 50000,
            );
        }

        $items = $task['result'][0]['items'] ?? [];
        $matched = $this->matchOrganicItem($items, $site);

        return [
            'position'            => $matched['rank'] ?? null,
            'ranking_url'         => $matched['url'] ?? null,
            'serp_features'       => $this->featuresOf($items),
            'provider_request_id' => isset($task['id']) ? mb_substr((string) $task['id'], 0, 64) : null,
            'cost_units'          => round((float) ($task['cost'] ?? $envelope['cost'] ?? 0), 4),
        ];
    }

    /** @return array<string, mixed> */
    private function buildTask(RankKeyword $keyword): array
    {
        $task = [
            'keyword' => trim((string) $keyword->keyword),
            'depth'   => $this->depth(),
            'device'  => $keyword->device === RankKeyword::DEVICE_MOBILE ? 'mobile' : 'desktop',
            'tag'     => (string) $keyword->id,
        ];

        $locationName = $this->locationNameFor($keyword);
        if ($locationName !== null) {
            $task['location_name'] = $locationName;
        }

        $task['language_name'] = $this->languageNameFor($keyword) ?? 'English';

        return $task;
    }

    private function timeout(): int
    {
        return max(5, (int) config('services.dataforseo.timeout', 60));
    }

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
     * @return array{rank: int, url: ?string}|null  null = no site listing inside the requested depth
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
                // Outside the requested depth (or bogus rank) = not found.
                if ($rank < 1 || $rank > $this->depth()) {
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
