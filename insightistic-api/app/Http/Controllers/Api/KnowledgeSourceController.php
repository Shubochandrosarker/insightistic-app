<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IngestKnowledgeSource;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeIngestionJob;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\TextExtractor;
use App\Services\UsageService;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Knowledge source lifecycle: add (file/url/sitemap/manual), inspect
 * processing state, retry, re-index, disable, delete (with chunk + vector
 * cleanup). All ingestion work runs on the queue — this controller only
 * validates, stores and dispatches.
 */
class KnowledgeSourceController extends Controller
{
    public function __construct(
        private UsageService $usage,
        private Tenancy $tenancy,
    ) {}

    public function index(int $baseId)
    {
        $base = KnowledgeBase::find($baseId);
        if (! $base) {
            return response()->json(['message' => 'Knowledge base not found.'], 404);
        }

        return response()->json([
            'sources' => $base->sources()
                ->select(['id', 'knowledge_base_id', 'site_id', 'type', 'title', 'source_url', 'mime_type',
                    'size_bytes', 'status', 'error_code', 'error_message', 'chunk_count', 'last_indexed_at', 'created_at'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request, int $baseId)
    {
        $base = KnowledgeBase::find($baseId);
        if (! $base) {
            return response()->json(['message' => 'Knowledge base not found.'], 404);
        }

        $org = $this->tenancy->organization();

        $data = $request->validate([
            'type'    => ['required', Rule::in(['file', 'url', 'sitemap', 'manual'])],
            'title'   => ['nullable', 'string', 'max:300'],
            'url'     => ['required_if:type,url,sitemap', 'nullable', 'url:http,https', 'max:2000'],
            'content' => ['required_if:type,manual', 'nullable', 'string', 'max:' . (int) config('insightistic.knowledge.max_content_bytes', 2_000_000)],
            'file'    => [
                'required_if:type,file', 'nullable', 'file',
                'max:' . ((int) config('insightistic.knowledge.max_file_mb', 20) * 1024), // KB
                'mimes:' . implode(',', array_keys(TextExtractor::EXTENSION_MIMES)),
                'mimetypes:' . implode(',', array_keys(TextExtractor::SUPPORTED_MIMES)),
            ],
        ]);

        $sizeBytes = match ($data['type']) {
            'file'   => (int) $request->file('file')->getSize(),
            'manual' => strlen((string) ($data['content'] ?? '')),
            default  => 0,
        };

        if (! $this->usage->canAddKnowledgeSource($org, $sizeBytes)) {
            return response()->json(
                $this->usage->limitError($org, 'knowledge_sources', $this->usage->knowledgeSourcesUsed($org)),
                402,
            );
        }

        $attributes = [
            'knowledge_base_id' => $base->id,
            'site_id'           => $base->site_id,
            'type'              => $data['type'],
            'status'            => 'pending',
            'size_bytes'        => $sizeBytes,
        ];

        switch ($data['type']) {
            case 'file':
                $file = $request->file('file');
                $attributes['title'] = $data['title'] ?? $file->getClientOriginalName();
                $attributes['mime_type'] = $this->resolveMime($file->getClientOriginalName(), (string) $file->getMimeType());
                $attributes['storage_path'] = $file->store('knowledge/' . $org->id, (string) config('insightistic.knowledge.disk', 'local'));
                break;

            case 'url':
            case 'sitemap':
                $attributes['title'] = $data['title'] ?? $data['url'];
                $attributes['source_url'] = $data['url'];
                break;

            case 'manual':
                $attributes['title'] = $data['title'] ?? 'Manual note';
                $attributes['mime_type'] = 'text/markdown';
                $attributes['metadata_json'] = ['content' => $data['content']];
                break;
        }

        $source = KnowledgeSource::create($attributes);
        $job = $this->queueIngestion($source);

        return response()->json([
            'source'        => $source,
            'ingestion_job' => $job->only(['id', 'status']),
        ], 201);
    }

    public function show(int $id)
    {
        $source = KnowledgeSource::with(['ingestionJobs' => fn ($q) => $q->latest()->limit(5)])->find($id);
        if (! $source) {
            return response()->json(['message' => 'Source not found.'], 404);
        }

        return response()->json(['source' => $source]);
    }

    /** Retry a failed ingestion (same content, fresh job). */
    public function retry(int $id)
    {
        return $this->requeue($id, onlyFailed: true);
    }

    /** Force re-index (e.g. the URL's content changed). */
    public function reindex(int $id)
    {
        return $this->requeue($id, onlyFailed: false, resetChecksum: true);
    }

    public function disable(int $id)
    {
        $source = KnowledgeSource::find($id);
        if (! $source) {
            return response()->json(['message' => 'Source not found.'], 404);
        }

        $source->update(['status' => 'disabled']);

        return response()->json(['source' => $source->fresh()]);
    }

    /**
     * Delete the source AND all associated chunks/embeddings. The response
     * reports the verified post-delete chunk count (spec Phase 10: vector
     * deletion verification).
     */
    public function destroy(int $id)
    {
        $source = KnowledgeSource::find($id);
        if (! $source) {
            return response()->json(['message' => 'Source not found.'], 404);
        }

        if ($source->storage_path) {
            Storage::disk((string) config('insightistic.knowledge.disk', 'local'))->delete($source->storage_path);
        }

        $sourceId = $source->id;
        $source->delete(); // knowledge_chunks cascade at the DB level

        $remaining = KnowledgeChunk::withoutGlobalScope('organization')
            ->where('knowledge_source_id', $sourceId)
            ->count();

        return response()->json([
            'message'          => 'Source deleted.',
            'remaining_chunks' => $remaining, // always 0 — verified, not assumed
        ]);
    }

    // -----------------------------------------------------------------

    private function requeue(int $id, bool $onlyFailed, bool $resetChecksum = false)
    {
        $source = KnowledgeSource::find($id);
        if (! $source) {
            return response()->json(['message' => 'Source not found.'], 404);
        }

        if ($onlyFailed && $source->status !== 'failed') {
            return response()->json(['message' => 'Only failed sources can be retried.'], 422);
        }
        if (in_array($source->status, ['processing'], true)) {
            return response()->json(['message' => 'Source is already being processed.'], 409);
        }

        if ($resetChecksum) {
            $source->forceFill(['checksum' => null])->save();
        }
        $source->update(['status' => 'pending', 'error_code' => null, 'error_message' => null]);

        $job = $this->queueIngestion($source);

        return response()->json(['source' => $source->fresh(), 'ingestion_job' => $job->only(['id', 'status'])], 202);
    }

    private function queueIngestion(KnowledgeSource $source): KnowledgeIngestionJob
    {
        $job = KnowledgeIngestionJob::create([
            'knowledge_base_id'   => $source->knowledge_base_id,
            'knowledge_source_id' => $source->id,
            'status'              => 'queued',
            'source_count'        => 1,
        ]);

        IngestKnowledgeSource::dispatch($source->id, $job->id);

        return $job;
    }

    /** Trust the extension→MIME map over browser-supplied types when they disagree. */
    private function resolveMime(string $filename, string $reported): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $byExtension = TextExtractor::EXTENSION_MIMES[$ext] ?? null;

        if ($byExtension && isset(TextExtractor::SUPPORTED_MIMES[strtolower($reported)])) {
            return $reported;
        }

        return $byExtension ?? $reported;
    }
}
