<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Models\Site;
use App\Services\UsageService;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Knowledge base CRUD. All queries run under the BelongsToOrganization
 * global scope — another tenant's knowledge base is a 404, not a 403.
 * Plan limits enforced server-side via UsageService (spec Phase 8).
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(
        private UsageService $usage,
        private Tenancy $tenancy,
    ) {}

    public function index()
    {
        $bases = KnowledgeBase::query()
            ->withCount(['sources', 'sources as indexed_sources_count' => fn ($q) => $q->where('status', 'indexed')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'knowledge_bases' => $bases,
            'usage'           => $this->usage->knowledgeUsageSummary($this->tenancy->organization()),
        ]);
    }

    public function store(Request $request)
    {
        $org = $this->tenancy->organization();

        if (! $this->usage->canCreateKnowledgeBase($org)) {
            return response()->json(
                $this->usage->limitError($org, 'knowledge_bases', $this->usage->knowledgeBasesUsed($org)),
                402,
            );
        }

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scope'       => ['nullable', Rule::in(KnowledgeBase::SCOPES)],
            'site_id'     => ['nullable', 'integer'],
        ]);

        // site_id must be one of THIS org's sites — resolved through the
        // tenant-scoped model, never trusted from the browser.
        $siteId = null;
        if (! empty($data['site_id'])) {
            $site = Site::find($data['site_id']);
            if (! $site) {
                return response()->json(['message' => 'Site not found.'], 404);
            }
            $siteId = $site->id;
        }

        $base = KnowledgeBase::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'scope'       => $data['scope'] ?? ($siteId ? 'site' : 'organization'),
            'site_id'     => $siteId,
            'status'      => 'active',
        ]);

        return response()->json(['knowledge_base' => $base], 201);
    }

    public function show(int $id)
    {
        $base = KnowledgeBase::withCount('sources')->find($id);
        if (! $base) {
            return response()->json(['message' => 'Knowledge base not found.'], 404);
        }

        return response()->json([
            'knowledge_base' => $base,
            'usage'          => $this->usage->knowledgeUsageSummary($this->tenancy->organization()),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $base = KnowledgeBase::find($id);
        if (! $base) {
            return response()->json(['message' => 'Knowledge base not found.'], 404);
        }

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status'      => ['sometimes', Rule::in(['active', 'archived'])],
        ]);

        $base->update($data);

        return response()->json(['knowledge_base' => $base]);
    }

    public function destroy(int $id)
    {
        $base = KnowledgeBase::find($id);
        if (! $base) {
            return response()->json(['message' => 'Knowledge base not found.'], 404);
        }

        // Remove stored files first; chunks/sources/jobs cascade at the DB.
        foreach ($base->sources()->whereNotNull('storage_path')->get() as $source) {
            \Illuminate\Support\Facades\Storage::disk((string) config('insightistic.knowledge.disk', 'local'))
                ->delete($source->storage_path);
        }

        $base->delete();

        return response()->json(['message' => 'Knowledge base deleted.']);
    }
}
