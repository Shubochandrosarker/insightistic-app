<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Services\Knowledge\KnowledgeRetriever;
use App\Services\UsageService;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * Test-retrieval panel + usage meters. The organization id given to the
 * retriever ALWAYS comes from the server-side tenant context.
 */
class KnowledgeSearchController extends Controller
{
    public function __construct(
        private KnowledgeRetriever $retriever,
        private UsageService $usage,
        private Tenancy $tenancy,
    ) {}

    public function testRetrieval(Request $request, int $baseId)
    {
        $base = KnowledgeBase::find($baseId);
        if (! $base) {
            return response()->json(['message' => 'Knowledge base not found.'], 404);
        }

        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $results = $this->retriever->search(
            $this->tenancy->id(),
            $data['query'],
            [
                'knowledge_base_ids' => [$base->id],
                'limit'              => $data['limit'] ?? 8,
            ],
        );

        return response()->json([
            'results' => $results->map(fn ($hit) => [
                'chunk_id'     => $hit['chunk']->id,
                'score'        => $hit['score'],
                'rank_sources' => $hit['rank_sources'],
                'content'      => mb_substr($hit['chunk']->content, 0, 600),
                'heading'      => $hit['chunk']->metadata_json['heading'] ?? null,
                'source'       => [
                    'id'              => $hit['chunk']->source?->id,
                    'title'           => $hit['chunk']->source?->title,
                    'type'            => $hit['chunk']->source?->type,
                    'url'             => $hit['chunk']->source?->source_url,
                    'last_indexed_at' => $hit['chunk']->source?->last_indexed_at,
                ],
            ]),
        ]);
    }

    public function usage()
    {
        return response()->json([
            'usage' => $this->usage->knowledgeUsageSummary($this->tenancy->organization()),
        ]);
    }
}
