<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\DataSourceConnection;
use App\Models\Site;
use App\Models\SyncLog;
use Illuminate\Http\Request;

class HandshakeController extends Controller
{
    /**
     * First call the plugin makes after the user pastes the connector key.
     * Confirms the key, records environment info, flips the site to connected.
     * Site + organization are already resolved by the ConnectorAuth middleware.
     */
    public function handshake(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('connector_site');

        $data = $request->validate([
            'site_name'      => ['nullable', 'string', 'max:190'],
            'domain'         => ['nullable', 'string', 'max:190'],
            'timezone'       => ['nullable', 'string', 'max:64'],
            'currency'       => ['nullable', 'string', 'max:8'],
            'wp_version'     => ['nullable', 'string', 'max:32'],
            'wc_version'     => ['nullable', 'string', 'max:32'],
            'plugin_version' => ['nullable', 'string', 'max:32'],
        ]);

        $site->fill([
            'name'              => $data['site_name'] ?? $site->name,
            'domain'            => $data['domain'] ?? $site->domain,
            'timezone'          => $data['timezone'] ?? $site->timezone,
            'currency'          => $data['currency'] ?? $site->currency,
            'wp_version'        => $data['wp_version'] ?? null,
            'wc_version'        => $data['wc_version'] ?? null,
            'plugin_version'    => $data['plugin_version'] ?? null,
            'connection_status' => 'connected',
        ])->save();

        SyncLog::create([
            'site_id'     => $site->id,
            'job'         => 'handshake',
            'status'      => 'success',
            'records'     => 0,
            'message'     => 'Handshake OK',
            'started_at'  => now(),
            'finished_at' => now(),
        ]);

        // The v1 plugin handshake is the source of truth for the optional
        // platform connectors. Older deployments only flipped the Site row,
        // which left the app's Integrations page showing “Not connected”
        // despite a healthy license and connector handshake.
        $this->recordPluginSource($site, 'wordpress');
        if ($site->wc_version) {
            $this->recordPluginSource($site, 'woocommerce');
        }

        $plan = $site->organization->plan;

        return response()->json([
            'status'  => 'ok',
            'site_id' => $site->id,
            'plan'    => [
                'name'             => $plan?->name,
                'ai_insight_limit' => $plan?->ai_insight_limit,
                'report_limit'     => $plan?->report_limit,
            ],
            'sync' => [
                // Chunk sizes the plugin should use (spec section 14).
                'orders_per_request'      => 50,
                'products_per_request'    => 100,
                'customers_per_request'   => 100,
                // Connector v2 (Milestone 3) — traffic/SEO/performance/
                // engagement/broken-link ingestion. A plugin built against
                // v1 only ignores this key entirely; only check it if the
                // plugin wants to detect v2 endpoint availability before
                // calling them (they 404 on servers that don't have it yet).
                'v2'                      => true,
                'traffic_days_per_request'    => 90,
                'seo_days_per_request'        => 90,
                'seo_rows_per_request'        => 250,
                'engagement_rows_per_request' => 400,
                'broken_links_per_request'    => 200,
            ],
        ]);
    }

    private function recordPluginSource(Site $site, string $source): void
    {
        DataSourceConnection::recordAttempt($site->organization_id, $site->id, $source, [
            'state'             => 'connected',
            'connection_method' => 'wordpress_plugin',
            'label'             => 'WordPress plugin',
            'last_attempted_at' => now(),
            'last_successful_at'=> now(),
            'latest_data_at'    => now(),
            'freshness'         => 'fresh',
            'metadata_json'     => ['plugin_fed_at' => now()->toIso8601String()],
        ]);
    }
}
