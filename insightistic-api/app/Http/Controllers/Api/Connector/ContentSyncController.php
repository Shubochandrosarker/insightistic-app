<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Jobs\IngestKnowledgeSource;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeIngestionJob;
use App\Models\KnowledgeSource;
use App\Services\UsageService;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Connector v2 — WordPress content ingestion for the AI knowledge base.
 * Authenticated by the connector HMAC (ConnectorAuth binds org + site).
 *
 * The plugin pushes published pages/posts/products the site owner opted in
 * to sharing (plugin-side privacy toggle, off by default). Content lands in
 * an auto-provisioned site-scoped "Website content" knowledge base and is
 * chunk-indexed by the normal queued pipeline. Idempotent per external id:
 * unchanged content (same checksum) is skipped by the ingestor.
 */
class ContentSyncController extends Controller
{
    public function __construct(
        private Tenancy $tenancy,
        private UsageService $usage,
    ) {}

    public function contentBulk(Request $request)
    {
        $site = $request->attributes->get('connector_site');
        $org  = $this->tenancy->organization();

        $data = $request->validate([
            'items'                => ['required', 'array', 'max:25'],
            'items.*.external_id'  => ['required', 'integer'],
            'items.*.type'         => ['required', Rule::in(['post', 'page', 'product'])],
            'items.*.title'        => ['required', 'string', 'max:300'],
            'items.*.url'          => ['nullable', 'string', 'max:2000'],
            'items.*.content'      => ['required', 'string', 'max:500000'],
            'items.*.modified_at'  => ['nullable', 'date'],
            'items.*.deleted'      => ['nullable', 'boolean'],
        ]);

        $base = KnowledgeBase::withoutGlobalScope('organization')->firstOrCreate(
            [
                'organization_id' => $org->id,
                'site_id'         => $site->id,
                'scope'           => 'site',
                'name'            => 'Website content',
            ],
            [
                'description' => 'Pages, posts and products synced from ' . $site->domain . ' by the Insightistic plugin.',
                'status'      => 'active',
            ],
        );

        $accepted = $deleted = $skipped = 0;

        foreach ($data['items'] as $item) {
            $sourceType = $item['type'] === 'product' ? 'product' : 'wordpress_post';

            $existing = KnowledgeSource::withoutGlobalScope('organization')
                ->where('organization_id', $org->id)
                ->where('knowledge_base_id', $base->id)
                ->where('type', $sourceType)
                ->where('metadata_json->external_id', (int) $item['external_id'])
                ->first();

            if (! empty($item['deleted'])) {
                if ($existing) {
                    $existing->delete(); // chunks cascade
                    $deleted++;
                }
                continue;
            }

            if (! $existing && ! $this->usage->canAddKnowledgeSource($org, strlen($item['content']))) {
                $skipped++; // plan limit — report, never silently pretend
                continue;
            }

            $source = $existing ?? new KnowledgeSource();
            $source->forceFill([
                'organization_id'   => $org->id,
                'site_id'           => $site->id,
                'knowledge_base_id' => $base->id,
                'type'              => $sourceType,
                'title'             => $item['title'],
                'source_url'        => $item['url'] ?? null,
                'mime_type'         => 'text/markdown',
                'status'            => 'pending',
                'size_bytes'        => strlen($item['content']),
                'metadata_json'     => array_merge($existing->metadata_json ?? [], [
                    'external_id' => (int) $item['external_id'],
                    'wp_type'     => $item['type'],
                    'modified_at' => $item['modified_at'] ?? null,
                    'content'     => $item['content'],
                ]),
            ])->save();

            $job = KnowledgeIngestionJob::withoutGlobalScope('organization')->create([
                'organization_id'     => $org->id,
                'knowledge_base_id'   => $base->id,
                'knowledge_source_id' => $source->id,
                'status'              => 'queued',
                'source_count'        => 1,
            ]);

            IngestKnowledgeSource::dispatch($source->id, $job->id);
            $accepted++;
        }

        return response()->json([
            'ok'                => true,
            'knowledge_base_id' => $base->id,
            'accepted'          => $accepted,
            'deleted'           => $deleted,
            'skipped_over_limit' => $skipped,
        ]);
    }
}
