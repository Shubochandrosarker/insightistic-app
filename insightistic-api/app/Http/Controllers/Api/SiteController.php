<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\WcOrder;
use App\Services\Sites\SiteKickoff;
use App\Support\DomainNormalizer;
use App\Support\Tenancy;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function index(Tenancy $tenancy)
    {
        return response()->json([
            'sites' => Site::query()->latest()->get([
                'id', 'name', 'domain', 'platform', 'detected_platform', 'platform_override',
                'normalized_domain', 'status',
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
            'name'     => ['nullable', 'string', 'max:120'],
            'domain'   => ['required', 'string', 'max:190'],
            // optional hints; platform is a user's explicit claim
            'platform' => ['nullable', 'string', 'max:32'],
            'country'  => ['nullable', 'string', 'size:2'],
            'language' => ['nullable', 'string', 'max:12'],
        ]);

        // Canonical normalization: example.com ≡ www.example.com ≡ https://example.com/
        try {
            $parts = DomainNormalizer::parse($data['domain']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_domain'], 422);
        }

        // Duplicate prevention: never create a second project for a domain
        // the organization already tracks.
        $existing = Site::query()
            ->where('normalized_domain', $parts['host_key'])
            ->whereNull('archived_at')
            ->first();
        if ($existing) {
            return response()->json([
                'message'      => 'This website is already in your workspace.',
                'code'         => 'duplicate_site',
                'site'         => $existing->only(['id', 'name', 'domain', 'platform']),
            ], 409);
        }

        $site = Site::create([
            'name'              => $data['name'] ?? $parts['host'],
            'domain'            => $parts['host'],
            'normalized_domain' => $parts['host_key'],
            'canonical_url'     => $parts['canonical'],
            'platform'          => 'generic',
            'platform_override' => $data['platform'] ?? null,
            'country'           => strtoupper($data['country'] ?? '') ?: null,
            'language'          => $data['language'] ?? null,
            'status'            => 'active',
            'connection_status' => 'pending',
        ]);

        // Immediately useful, plugin-free: crawl + uptime + PageSpeed + platform
        // detection all start now. The connector token below is only needed if
        // the user LATER installs the WordPress plugin.
        $kickoff = app(SiteKickoff::class)->start($site);
        $token = $site->issueConnectorToken();

        try {
            app(\App\Services\Analytics\AttributionService::class)
                ->event('site_added', $org, $request->user(), [
                    'domain'   => $parts['host_key'],
                    'platform' => 'generic',
                    'method'   => 'url',
                ]);
        } catch (\Throwable) {
        }

        return response()->json([
            'site'            => $site->only(['id', 'name', 'domain', 'platform', 'normalized_domain', 'canonical_url', 'status', 'connection_status']),
            'kickoff'         => $kickoff,
            'connector_token' => $token,
            'notice'          => 'Initial audit, uptime monitoring and PageSpeed are running. The connector token is only needed if you later install the WordPress plugin.',
        ], 201);
    }

    public function show(Request $request)
    {
        // Site resolved + access-checked by EnsureSiteAccess middleware.
        $site = $request->attributes->get('site');

        return response()->json(['site' => $site]);
    }

    /** Connection / sync health + REAL composite site health for Overview. */
    public function health(Request $request)
    {
        $site = $request->attributes->get('site');

        $logs = $site->syncLogs()
            ->latest('id')
            ->limit(12)
            ->get(['job', 'status', 'records', 'message', 'started_at', 'finished_at']);

        return response()->json([
            'site' => $site->only([
                'id', 'name', 'domain', 'platform', 'detected_platform', 'platform_override',
                'normalized_domain', 'status', 'connection_status', 'last_sync_at',
                'wp_version', 'wc_version', 'plugin_version', 'timezone', 'currency',
            ]),
            // The REAL health (spec §19): crawl + SSL + uptime composite —
            // never the WordPress connector status.
            'health' => app(\App\Services\Sites\SiteHealthService::class)->composite($site),
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
