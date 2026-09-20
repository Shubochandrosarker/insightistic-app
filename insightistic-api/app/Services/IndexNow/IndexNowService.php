<?php

namespace App\Services\IndexNow;

use App\Models\IndexNowKey;
use App\Models\IndexNowSubmission;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * IndexNow submission engine (Phase 6, docs/roadmap-2026/11-BING-WEBMASTER-
 * INDEXNOW §IndexNow).
 *
 * Division of labour: the SaaS generates/stores the key and decides WHAT to
 * submit; the WordPress plugin HOSTS the key file at https://{domain}/{key}.txt
 * (the SaaS never serves it) and pushes eligible URLs via the connector.
 *
 * Submission is synchronous and bounded: at most a few thousand URLs per call
 * (validation caps arrays at 1000), chunked into <=1000-URL batch POSTs with a
 * 15s timeout, and rate-limited at the route layer. Submission is a discovery
 * signal, not an indexing promise — we log exactly what the endpoint said and
 * retry only what the protocol calls retryable (429 / 5xx / transport errors).
 *
 * The key is a public value by design (it appears in the key file URL), but it
 * is still never written into error messages or logs.
 */
class IndexNowService
{
    /** Batch endpoint (single-URL GET form exists too; batches are the norm). */
    public const ENDPOINT = 'https://api.indexnow.org/IndexNow';

    /** Per-URL dedupe window — IndexNow asks not to resubmit known URLs. */
    public const DEDUPE_HOURS = 24;

    /** Protocol limit: at most 10,000 URLs per batch POST; we stay well under. */
    public const CHUNK_SIZE = 1000;

    public const TIMEOUT_SECONDS = 15;

    /**
     * Ensure the site has a key WITHOUT rotating an existing one — the plugin
     * may have already written the key file, so re-reading the key endpoint
     * must be stable. 32 hex chars (protocol allows 8-128 hex).
     */
    public function generateKey(Site $site): IndexNowKey
    {
        return IndexNowKey::firstOrCreate(
            ['site_id' => $site->id],
            ['key' => bin2hex(random_bytes(16))]
        );
    }

    /** Force a NEW key (owner action): invalidates the old key file and the confirmation. */
    public function regenerateKey(Site $site): IndexNowKey
    {
        return IndexNowKey::updateOrCreate(
            ['site_id' => $site->id],
            ['key' => bin2hex(random_bytes(16)), 'key_file_confirmed' => false]
        );
    }

    /** The plugin reported it serves {key}.txt — record that (drives the 403 explanation). */
    public function confirmKeyFile(Site $site): IndexNowKey
    {
        $key = $this->generateKey($site);
        $key->forceFill(['key_file_confirmed' => true])->save();

        return $key;
    }

    /** Dashboard status: key + confirmation + rolling submission facts. */
    public function keyStatus(Site $site): array
    {
        $key = IndexNowKey::where('site_id', $site->id)->first();

        $counts = IndexNowSubmission::where('site_id', $site->id)
            ->whereIn('status', [IndexNowSubmission::STATUS_ACCEPTED, IndexNowSubmission::STATUS_FAILED, IndexNowSubmission::STATUS_PENDING])
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        return [
            'key'                => $key?->key,
            'key_location'       => $key !== null ? 'https://' . $site->domain . '/' . $key->key . '.txt' : null,
            'key_file_confirmed' => (bool) $key?->key_file_confirmed,
            'last_submitted_at'  => IndexNowSubmission::where('site_id', $site->id)->max('created_at'),
            'counts'             => [
                'accepted' => (int) ($counts[IndexNowSubmission::STATUS_ACCEPTED] ?? 0),
                'failed'   => (int) ($counts[IndexNowSubmission::STATUS_FAILED] ?? 0),
                'pending'  => (int) ($counts[IndexNowSubmission::STATUS_PENDING] ?? 0),
            ],
        ];
    }

    /**
     * Submit a list of URLs for one site. Returns per-outcome counts; never
     * throws on provider errors from the HTTP path ($rethrow is for a future
     * queued-job caller that needs the exception for retry accounting).
     *
     * Pipeline per URL: normalize -> host eligibility -> in-request dedupe ->
     * 24h cache dedupe lock -> pending log row -> batch POST -> resolve rows.
     *
     * @return array{batch_id: string, submitted: int, accepted: int, failed: int, skipped_duplicate: int, skipped_invalid_host: int, error?: string}
     */
    public function submit(Site $site, array $urls, string $source = IndexNowSubmission::SOURCE_MANUAL, bool $rethrow = false): array
    {
        $key = IndexNowKey::where('site_id', $site->id)->first();

        if ($key === null) {
            if ($rethrow) {
                throw new \RuntimeException('IndexNow is not initialized for this site.');
            }

            // Nothing was logged and nothing can be — the caller (connector)
            // maps this to 422 indexnow_not_initialized before reaching here.
            return [
                'batch_id'             => (string) Str::uuid(),
                'submitted'            => 0,
                'accepted'             => 0,
                'failed'               => 0,
                'skipped_duplicate'    => 0,
                'skipped_invalid_host' => count($urls),
                'error'                => 'indexnow_not_initialized',
            ];
        }

        $host = strtolower((string) $site->domain);

        // 1. Normalize + eligibility + in-request dedupe (url => sha1).
        $candidates = [];
        $skippedInvalidHost = 0;

        foreach ($urls as $url) {
            $normalized = $this->normalizeUrl((string) $url);

            if ($normalized === null || strcasecmp($this->hostOf($normalized), $host) !== 0) {
                $skippedInvalidHost++;
                continue;
            }

            $candidates[$normalized] ??= sha1($normalized);
        }

        $batchId = (string) Str::uuid();

        // 2. Dedupe against recent submissions (cache lock, 24h).
        $eligible = [];
        $skippedDuplicate = 0;

        foreach ($candidates as $url => $hash) {
            if (Cache::has($this->dedupeCacheKey($site->id, $hash))) {
                $skippedDuplicate++;
                $this->logRow($site->id, $url, $hash, $source, $batchId, IndexNowSubmission::STATUS_DUPLICATE);
                continue;
            }

            $eligible[$url] = $hash;
        }

        // 3. Pending log rows (batch grain: UNIQUE(batch_id, url_hash)).
        foreach ($eligible as $url => $hash) {
            $this->logRow($site->id, $url, $hash, $source, $batchId, IndexNowSubmission::STATUS_PENDING);
        }

        $result = [
            'batch_id'             => $batchId,
            'submitted'            => count($eligible),
            'accepted'             => 0,
            'failed'               => 0,
            'skipped_duplicate'    => $skippedDuplicate,
            'skipped_invalid_host' => $skippedInvalidHost,
        ];

        if ($eligible === []) {
            return $result;
        }

        // 4. Batch POST in protocol-sized chunks (15s timeout each).
        foreach (array_chunk(array_keys($eligible), self::CHUNK_SIZE, preserve_keys: true) as $chunk) {
            $result = $this->postChunk($site, $key, $batchId, $chunk, $eligible, $result, $rethrow);
        }

        return $result;
    }

    /**
     * Send one chunk and resolve its pending rows. Transport failure is a
     * fail-safe: every URL in the chunk is marked failed/retryable, and the
     * counted result is returned (rethrown only when $rethrow is set).
     */
    private function postChunk(Site $site, IndexNowKey $key, string $batchId, array $chunkUrls, array $eligible, array $result, bool $rethrow): array
    {
        try {
            $response = Http::asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::ENDPOINT, [
                    'host'        => $site->domain,
                    'key'         => $key->key,
                    'keyLocation' => 'https://' . $site->domain . '/' . $key->key . '.txt',
                    'urlList'     => array_values($chunkUrls),
                ]);
        } catch (ConnectionException $e) {
            $this->resolveRows($site->id, $batchId, $chunkUrls, IndexNowSubmission::STATUS_FAILED, null, true, 'IndexNow endpoint unreachable (connection error).');
            $result['failed'] += count($chunkUrls);
            $result['error'] = 'indexnow_unreachable';

            if ($rethrow) {
                throw $e;
            }

            return $result;
        }

        $status = $response->status();

        if (in_array($status, [200, 202], true)) {
            foreach ($chunkUrls as $url) {
                // Accepted = search engines now know the URL; mark it so a
                // repost within the window is skipped instead of resubmitted.
                Cache::put($this->dedupeCacheKey($site->id, $eligible[$url]), 1, now()->addHours(self::DEDUPE_HOURS));
            }

            $this->resolveRows($site->id, $batchId, $chunkUrls, IndexNowSubmission::STATUS_ACCEPTED, $status, false, null);
            $result['accepted'] += count($chunkUrls);

            return $result;
        }

        $retryable = $status === 429 || $status >= 500;
        $this->resolveRows($site->id, $batchId, $chunkUrls, IndexNowSubmission::STATUS_FAILED, $status, $retryable, $this->errorMessage($status));
        $result['failed'] += count($chunkUrls);

        return $result;
    }

    /**
     * Resolve this batch's pending rows for the chunk's URLs in place.
     * batch_id scopes the update to exactly the rows this call created —
     * an older pending row from a previous failed batch is left alone.
     */
    private function resolveRows(int $siteId, string $batchId, array $chunkUrls, string $status, ?int $httpStatus, bool $retryable, ?string $error): void
    {
        IndexNowSubmission::where('site_id', $siteId)
            ->where('batch_id', $batchId)
            ->where('status', IndexNowSubmission::STATUS_PENDING)
            ->whereIn('url', array_values($chunkUrls))
            ->update([
                'status'      => $status,
                'http_status' => $httpStatus,
                'retryable'   => $retryable,
                'error'       => $error,
            ]);
    }

    private function logRow(int $siteId, string $url, string $hash, string $source, string $batchId, string $status): IndexNowSubmission
    {
        return IndexNowSubmission::create([
            'site_id'    => $siteId,
            'url'        => $url,
            'url_hash'   => $hash,
            'source'     => $source,
            'batch_id'   => $batchId,
            'status'     => $status,
            'created_at' => now(),
        ]);
    }

    /**
     * Canonical form for submission + hashing: https scheme (IndexNow is an
     * https-signal protocol), lowercase host, fragment stripped (fragments
     * never reach a server), path + query preserved. Returns null when the
     * string is not parseable as an absolute URL.
     */
    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 1000) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return 'https://' . $host . $path . $query;
    }

    private function hostOf(string $normalizedUrl): string
    {
        return (string) parse_url($normalizedUrl, PHP_URL_HOST);
    }

    private function dedupeCacheKey(int $siteId, string $hash): string
    {
        return "indexnow:{$siteId}:{$hash}";
    }

    /** Human-readable, key-free explanation per the IndexNow status codes. */
    private function errorMessage(int $status): string
    {
        return match (true) {
            $status === 400 => 'IndexNow rejected the batch as malformed (HTTP 400).',
            $status === 403 => 'IndexNow could not validate the key — the key file was not found or does not match (HTTP 403).',
            $status === 422 => 'IndexNow rejected the URLs — they do not belong to the site host (HTTP 422).',
            $status === 429 => 'IndexNow rate limit hit (HTTP 429) — safe to retry later.',
            $status >= 500  => "IndexNow server error (HTTP {$status}) — safe to retry later.",
            default         => "IndexNow returned an unexpected response (HTTP {$status}).",
        };
    }
}
