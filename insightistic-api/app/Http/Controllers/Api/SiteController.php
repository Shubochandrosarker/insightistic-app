<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\WcOrder;
use App\Support\Tenancy;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function index(Tenancy $tenancy)
    {
        return response()->json([
            'sites' => Site::query()->latest()->get([
                'id', 'name', 'domain', 'platform',
                'connection_status', 'last_sync_at', 'wc_version', 'plugin_version',
            ]),
        ]);
    }

    public function store(Request $request, Tenancy $tenancy)
    {
        $org = $tenancy->organization();
        $plan = $org->plan;

        // Enforce plan site limit (spec Mistake #3 / plan limits).
        $limit = $plan?->site_limit ?? 1;
        if (Site::query()->count() >= $limit) {
            return response()->json([
                'message' => "Site limit reached for your plan ({$limit}). Upgrade to add more.",
                'code'    => 'site_limit_reached',
            ], 402);
        }

        $data = $request->validate([
            'name'   => ['required', 'string', 'max:120'],
            'domain' => ['nullable', 'string', 'max:190'],
        ]);

        $site = Site::create([
            'name'              => $data['name'],
            'domain'            => $data['domain'] ?? null,
            'platform'          => 'woocommerce',
            'connection_status' => 'pending',
        ]);

        // One-time setup token: "<key_id>.<secret>". The plugin keeps it and
        // signs every request locally — the secret never travels again.
        $token = $site->issueConnectorToken();

        return response()->json([
            'site'            => $site->only(['id', 'name', 'domain', 'connection_status']),
            'connector_token' => $token,
            'notice'          => 'Copy this connector token now — it will not be shown again.',
        ], 201);
    }

    public function show(Request $request)
    {
        // Site resolved + access-checked by EnsureSiteAccess middleware.
        $site = $request->attributes->get('site');

        return response()->json(['site' => $site]);
    }

    /** Connection / sync health + recent sync log for the Site Health page. */
    public function health(Request $request)
    {
        $site = $request->attributes->get('site');

        $logs = $site->syncLogs()
            ->latest('id')
            ->limit(12)
            ->get(['job', 'status', 'records', 'message', 'started_at', 'finished_at']);

        return response()->json([
            'site' => $site->only([
                'id', 'name', 'domain', 'connection_status', 'last_sync_at',
                'wp_version', 'wc_version', 'plugin_version', 'timezone', 'currency',
            ]),
            'orders_total' => WcOrder::where('site_id', $site->id)->count(),
            'sync_logs'    => $logs,
        ]);
    }

    public function regenerateApiKey(Request $request)
    {
        $site = $request->attributes->get('site');
        $token = $site->issueConnectorToken();
        $site->update(['connection_status' => 'pending']);

        return response()->json([
            'connector_token' => $token,
            'notice'          => 'Old token is now invalid. Update the plugin with this new token.',
        ]);
    }
}
