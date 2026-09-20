<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IndexNowSubmission;
use App\Models\Site;
use App\Services\IndexNow\IndexNowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IndexNow dashboard surface (Phase 6, docs/roadmap-2026/11-BING-WEBMASTER-
 * INDEXNOW §URL submission UI): key status + submission history for a site,
 * manual submit (owner/admin), and key regeneration (owner).
 *
 * The key FILE itself is hosted by the WordPress plugin, not by us — this
 * endpoint only shows what the key is, where it must live, and whether the
 * plugin has confirmed it serves it.
 */
class IndexNowController extends Controller
{
    public function __construct(private readonly IndexNowService $indexNow)
    {
    }

    /** GET /sites/{site}/indexnow — status + last 100 submissions. */
    public function index(Request $request): JsonResponse
    {
        $site = $this->site($request);

        return response()->json([
            'status'      => $this->indexNow->keyStatus($site),
            'submissions' => IndexNowSubmission::where('site_id', $site->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'url', 'source', 'batch_id', 'status', 'http_status', 'retryable', 'error', 'created_at']),
        ]);
    }

    /** POST /sites/{site}/indexnow/submit — manual submission (role:owner,admin). */
    public function submit(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $data = $request->validate([
            'urls'   => ['required', 'array', 'max:1000'],
            'urls.*' => ['required', 'string', 'url', 'max:1000'],
        ]);

        // A manual submit on a site nobody initialized yet still works — the
        // key is generated lazily; a missing key FILE surfaces later as an
        // honest 403/failed row, not as a hard 422 here.
        $this->indexNow->generateKey($site);

        return response()->json($this->indexNow->submit($site, $data['urls'], IndexNowSubmission::SOURCE_MANUAL));
    }

    /** POST /sites/{site}/indexnow/regenerate-key — force a new key (role:owner). */
    public function regenerateKey(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $this->indexNow->regenerateKey($site);

        return response()->json(['status' => $this->indexNow->keyStatus($site)]);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
