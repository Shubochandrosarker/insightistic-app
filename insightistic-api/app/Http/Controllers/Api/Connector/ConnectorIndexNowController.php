<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\IndexNowKey;
use App\Models\IndexNowSubmission;
use App\Models\Site;
use App\Services\IndexNow\IndexNowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IndexNow bridge for the WordPress plugin (HMAC, docs/roadmap-2026/11-
 * BING-WEBMASTER-INDEXNOW §IndexNow/Plugin workflow).
 *
 * Plugin flow:
 *   1. GET  connector/v1/indexnow/key          -> key (generated on first call)
 *   2. write https://{domain}/{key}.txt on the WordPress side
 *   3. GET  connector/v1/indexnow/key?confirmed=1 -> we record the confirmation
 *   4. POST connector/v1/indexnow/submit {urls} -> SaaS submits to IndexNow
 *
 * Submitting before step 1 is a hard 422 (no key = nothing to verify against).
 */
class ConnectorIndexNowController extends Controller
{
    public function __construct(private readonly IndexNowService $indexNow)
    {
    }

    /**
     * GET/POST /connector/v1/indexnow/key — return (lazily creating) the
     * site's key. `?confirmed=1` records that the plugin now serves the key
     * file; POST exists so the plugin can use the same path with a body.
     */
    public function key(Request $request): JsonResponse
    {
        $site = $this->site($request);

        if ($request->boolean('confirmed')) {
            $key = $this->indexNow->confirmKeyFile($site);
        } else {
            $key = $this->indexNow->generateKey($site);
        }

        return response()->json([
            'key'                => $key->key,
            'key_location'       => 'https://' . $site->domain . '/' . $key->key . '.txt',
            'key_file_confirmed' => (bool) $key->key_file_confirmed,
        ]);
    }

    /** POST /connector/v1/indexnow/submit — plugin pushes eligible URLs (source=plugin). */
    public function submit(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $data = $request->validate([
            'urls'   => ['required', 'array', 'max:1000'],
            'urls.*' => ['required', 'string', 'url', 'max:1000'],
        ]);

        if (! IndexNowKey::where('site_id', $site->id)->exists()) {
            return response()->json([
                'message' => 'IndexNow is not initialized for this site — fetch the key first.',
                'code'    => 'indexnow_not_initialized',
            ], 422);
        }

        return response()->json($this->indexNow->submit($site, $data['urls'], IndexNowSubmission::SOURCE_PLUGIN));
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('connector_site');
    }
}
