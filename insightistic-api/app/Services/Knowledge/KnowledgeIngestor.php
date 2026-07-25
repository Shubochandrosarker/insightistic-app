<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeIngestionJob;
use App\Models\KnowledgeSource;
use App\Services\UsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Runs one knowledge source through the full pipeline:
 *   load → extract → clean → chunk → dedup → (embed) → store → mark indexed.
 *
 * Always executed from the queue (App\Jobs\IngestKnowledgeSource). Every
 * failure path records error_code/error_message on the source AND the
 * ingestion job — ingestion never fails silently (spec: "Do not silently
 * swallow important errors").
 */
class KnowledgeIngestor
{
    public const MAX_PAGES_PER_SITEMAP = 50;

    public function __construct(
        private TextExtractor $extractor,
        private Chunker $chunker,
        private EmbeddingProvider $embeddings,
        private SafeUrlFetcher $fetcher,
        private UsageService $usage,
    ) {}

    public function ingest(KnowledgeSource $source, KnowledgeIngestionJob $job): void
    {
        $startedAt = microtime(true);
        $job->update(['status' => 'processing', 'started_at' => now()]);
        $source->update(['status' => 'processing', 'error_code' => null, 'error_message' => null]);

        try {
            [$text, $meta] = $this->loadContent($source);

            $checksum = hash('sha256', $text);

            // Fast no-op: content unchanged since the last successful index.
            if ($source->checksum === $checksum && $source->status !== 'failed' && $source->chunk_count > 0) {
                $this->finish($source, $job, $source->chunk_count, 0, $startedAt);

                return;
            }

            // Cross-source duplicate inside the same knowledge base.
            $duplicate = KnowledgeSource::withoutGlobalScope('organization')
                ->where('organization_id', $source->organization_id)
                ->where('knowledge_base_id', $source->knowledge_base_id)
                ->where('id', '!=', $source->id)
                ->where('checksum', $checksum)
                ->where('status', 'indexed')
                ->exists();
            if ($duplicate) {
                throw new IngestionFailure('duplicate_content', 'Identical content is already indexed in this knowledge base.');
            }

            if (trim($text) === '') {
                throw new IngestionFailure('empty_content', 'No extractable text found in this source.');
            }

            $chunks = $this->chunker->chunk($text, $meta);
            if ($chunks === []) {
                throw new IngestionFailure('empty_content', 'Content produced no indexable chunks.');
            }

            // Plan limit: indexed chunks (org-wide, live count minus this
            // source's soon-to-be-replaced chunks).
            $org = $source->organization()->first();
            $existingOwn = KnowledgeChunk::withoutGlobalScope('organization')
                ->where('knowledge_source_id', $source->id)->count();
            $projected = $this->usage->indexedChunksUsed($org) - $existingOwn + count($chunks);
            if ($projected > $this->usage->limit($org, 'indexed_chunks')) {
                throw new IngestionFailure('limit_reached', 'Indexing this source would exceed your plan\'s indexed chunk limit.');
            }

            // Embeddings: metered, batched; provider failure downgrades to
            // FTS-only rather than failing the whole ingestion.
            $vectors = null;
            if (VectorSupport::enabled() && $this->embeddings->model() !== 'none') {
                if ($this->usage->canEmbed($org, count($chunks))) {
                    $vectors = $this->embedAll(array_column($chunks, 'content'));
                    if ($vectors !== null) {
                        $this->usage->recordEmbeddings($org, count($chunks));
                    }
                }
            }

            $tokenTotal = $this->storeChunks($source, $chunks, $vectors);

            $source->forceFill([
                'checksum'   => $checksum,
                'size_bytes' => max((int) $source->size_bytes, strlen($text)),
            ])->save();

            $this->finish($source, $job, count($chunks), $tokenTotal, $startedAt);
        } catch (IngestionFailure $e) {
            $this->fail($source, $job, $e->code, $e->getMessage(), $startedAt);
        } catch (SsrfViolation $e) {
            $this->fail($source, $job, 'blocked_url', $e->getMessage(), $startedAt);
        } catch (\Throwable $e) {
            report($e);
            $this->fail($source, $job, 'ingestion_error', $e->getMessage(), $startedAt);
        }
    }

    /** @return array{0: string, 1: array} [plain text, base chunk metadata] */
    private function loadContent(KnowledgeSource $source): array
    {
        switch ($source->type) {
            case 'manual':
            case 'wordpress_post':
            case 'product':
            case 'integration':
                $content = (string) ($source->metadata_json['content'] ?? '');
                // Stored as markdown/plain by the API; treat as text.
                return [$content, ['origin' => $source->type]];

            case 'file':
                if (! $source->storage_path || ! Storage::disk($this->disk())->exists($source->storage_path)) {
                    throw new IngestionFailure('missing_file', 'Uploaded file is no longer available.');
                }
                $bytes = Storage::disk($this->disk())->get($source->storage_path);

                return [$this->extractor->extract($bytes, (string) $source->mime_type), ['origin' => 'file']];

            case 'url':
                return $this->loadUrl($source->source_url);

            case 'sitemap':
                return $this->loadSitemap($source);

            default:
                throw new IngestionFailure('unsupported_type', "Unknown source type {$source->type}.");
        }
    }

    /** @return array{0: string, 1: array} */
    private function loadUrl(?string $url): array
    {
        if (! $url) {
            throw new IngestionFailure('missing_url', 'No URL configured for this source.');
        }

        $res = $this->fetcher->fetch($url);
        $type = strtolower(explode(';', $res['content_type'])[0] ?: 'text/html');

        if (str_contains($type, 'html') && $this->extractor->htmlHasNoindex($res['body'])) {
            throw new IngestionFailure('noindex', 'The page opts out of indexing (robots noindex).');
        }

        $mime = str_contains($type, 'html') ? 'text/html'
            : (isset(TextExtractor::SUPPORTED_MIMES[$type]) ? $type : 'text/html');

        return [$this->extractor->extract($res['body'], $mime), ['origin' => 'url', 'url' => $res['final_url']]];
    }

    /** @return array{0: string, 1: array} */
    private function loadSitemap(KnowledgeSource $source): array
    {
        if (! $source->source_url) {
            throw new IngestionFailure('missing_url', 'No sitemap URL configured.');
        }

        $res = $this->fetcher->fetch($source->source_url);

        if (! preg_match_all('/<loc>\s*([^<\s]+)\s*<\/loc>/i', $res['body'], $m) || $m[1] === []) {
            throw new IngestionFailure('empty_sitemap', 'No URLs found in the sitemap.');
        }

        $urls = array_slice(array_values(array_unique($m[1])), 0, self::MAX_PAGES_PER_SITEMAP);
        $skipped = [];
        $sections = [];

        foreach ($urls as $url) {
            try {
                [$text, $meta] = $this->loadUrl(html_entity_decode($url));
                if (trim($text) !== '') {
                    $sections[] = '# ' . ($meta['url'] ?? $url) . "\n\n" . $text;
                }
            } catch (\Throwable $e) {
                $skipped[] = ['url' => $url, 'reason' => substr($e->getMessage(), 0, 200)];
            }
        }

        // Record what was dropped — silent truncation would read as full coverage.
        $source->forceFill([
            'metadata_json' => array_merge($source->metadata_json ?? [], [
                'pages_indexed' => count($sections),
                'pages_skipped' => $skipped,
                'page_cap'      => self::MAX_PAGES_PER_SITEMAP,
            ]),
        ])->save();

        if ($sections === []) {
            throw new IngestionFailure('crawl_failed', 'None of the sitemap URLs could be fetched.');
        }

        return [implode("\n\n", $sections), ['origin' => 'sitemap']];
    }

    /** @return list<list<float>>|null */
    private function embedAll(array $texts): ?array
    {
        $all = [];
        foreach (array_chunk($texts, 64) as $batch) {
            $vectors = $this->embeddings->embed($batch);
            if ($vectors === null) {
                return null; // provider down/unconfigured — FTS-only, not fake
            }
            array_push($all, ...$vectors);
        }

        return $all;
    }

    private function storeChunks(KnowledgeSource $source, array $chunks, ?array $vectors): int
    {
        $tokenTotal = 0;

        DB::transaction(function () use ($source, $chunks, $vectors, &$tokenTotal) {
            // Versioned re-index: replace this source's chunks atomically.
            KnowledgeChunk::withoutGlobalScope('organization')
                ->where('knowledge_source_id', $source->id)
                ->delete();

            foreach ($chunks as $i => $chunk) {
                $row = KnowledgeChunk::create([
                    'organization_id'     => $source->organization_id,
                    'site_id'             => $source->site_id,
                    'knowledge_source_id' => $source->id,
                    'chunk_index'         => $i,
                    'content'             => $chunk['content'],
                    'content_hash'        => hash('sha256', $chunk['content']),
                    'token_count'         => $chunk['token_count'],
                    'metadata_json'       => $chunk['metadata'],
                    'embedding_model'     => $vectors !== null ? $this->embeddings->model() : null,
                ]);

                if ($vectors !== null && isset($vectors[$i])) {
                    DB::update(
                        'UPDATE knowledge_chunks SET embedding = ?::vector WHERE id = ?',
                        [VectorSupport::toLiteral($vectors[$i]), $row->id],
                    );
                }

                $tokenTotal += (int) $chunk['token_count'];
            }
        });

        return $tokenTotal;
    }

    private function finish(KnowledgeSource $source, KnowledgeIngestionJob $job, int $chunkCount, int $tokens, float $startedAt): void
    {
        $source->forceFill([
            'status'          => 'indexed',
            'chunk_count'     => $chunkCount,
            'last_indexed_at' => now(),
            'error_code'      => null,
            'error_message'   => null,
        ])->save();

        $job->update([
            'status'      => 'completed',
            'source_count' => 1,
            'chunk_count' => $chunkCount,
            'token_count' => $tokens,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'finished_at' => now(),
        ]);
    }

    private function fail(KnowledgeSource $source, KnowledgeIngestionJob $job, string $code, string $message, float $startedAt): void
    {
        $source->forceFill([
            'status'        => 'failed',
            'error_code'    => $code,
            'error_message' => mb_substr($message, 0, 1000),
        ])->save();

        $job->update([
            'status'        => 'failed',
            'error_code'    => $code,
            'error_message' => mb_substr($message, 0, 1000),
            'duration_ms'   => (int) ((microtime(true) - $startedAt) * 1000),
            'finished_at'   => now(),
        ]);
    }

    private function disk(): string
    {
        return (string) config('insightistic.knowledge.disk', config('filesystems.default', 'local'));
    }
}
