<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ConnectorAuth;
use App\Models\Site;
use Illuminate\Http\Request;

class PingController extends Controller
{
    /**
     * Read-only diagnostic. Reaching this method at all means the full HMAC chain
     * (key id, secret, timestamp window, nonce, signature) verified successfully —
     * the site + organization were already resolved by the ConnectorAuth middleware.
     *
     * Unlike handshake, this mutates nothing and writes no log, so the plugin can
     * call it freely to test a connection. It also returns server time so the plugin
     * can surface clock drift, the most common cause of signature rejection.
     */
    public function ping(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('connector_site');

        return response()->json([
            'status' => 'ok',
            'site'   => [
                'id'                => $site->id,
                'name'              => $site->name,
                'connection_status' => $site->connection_status,
                'last_sync_at'      => optional($site->last_sync_at)->toIso8601String(),
            ],
            'plan'   => $site->organization->plan?->name,
            'server' => [
                'time_iso'           => now()->toIso8601String(),
                'time_unix'          => now()->timestamp,
                'skew_window_seconds'=> ConnectorAuth::SKEW_SECONDS,
            ],
        ]);
    }
}
