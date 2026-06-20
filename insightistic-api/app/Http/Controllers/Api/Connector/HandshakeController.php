<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
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
                'orders_per_request'    => 50,
                'products_per_request'  => 100,
                'customers_per_request' => 100,
            ],
        ]);
    }
}
