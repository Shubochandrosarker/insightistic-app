<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\CloudflareTrafficService;
use App\Services\LicenseService;
use Illuminate\Http\Request;

/**
 * Serves the WordPress plugin's hosted, zero-config Cloudflare Traffic
 * Insights path — see insightistic/docs/APP-CONNECT-WORKFLOW.md §10 for the
 * frozen contract. A connected free account is the only credential; no Zone
 * ID or API token ever comes from the plugin. This site's Cloudflare zone
 * (if any) is looked up on the Site row via `cloudflare_zone_id`/
 * `cloudflare_api_token` — populated once a "connect Cloudflare" flow exists
 * on the Insightistic dashboard (not yet built; out of scope here).
 *
 * `available: false` for a site with no linked zone is an expected, calm
 * state, not an error — every site is in this state until that dashboard
 * flow ships.
 */
class PluginCloudflareController extends Controller
{
    public function __construct(
        private LicenseService $licenses,
        private CloudflareTrafficService $cloudflare,
    ) {}

    public function trafficData(Request $request)
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        $days = $data['days'] ?? 28;

        [$license] = $this->licenses->resolveFromConnector($request);
        if (! $license) {
            return $this->error('no_license', 'No active license for this site. Re-activate with your key.', 403);
        }

        $org = Organization::find($license->organization_id);
        $license->status = $this->licenses->computeStatus($org, $license);
        if (! $license->isEntitled()) {
            return $this->error('no_license', 'Your Insightistic account is not active. Please check your billing.', 403);
        }

        $site = $request->attributes->get('connector_site');
        if (! $site || ! $site->hasCloudflareLinked()) {
            return response()->json(['available' => false]);
        }

        $result = $this->cloudflare->fetch($site, $days);
        if ($result === null) {
            return $this->error(
                'cloudflare_error',
                'Cloudflare Traffic Insights is temporarily unavailable. Please try again shortly.',
                502,
            );
        }

        return response()->json(['available' => true, 'data' => $result]);
    }

    private function error(string $code, string $message, int $status)
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
