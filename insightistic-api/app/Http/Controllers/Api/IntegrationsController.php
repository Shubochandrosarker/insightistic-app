<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataSourceConnection;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Data-source health (spec §7.4, §10.6). One row per (site, source) —
 * connected/not_configured/syncing/degraded/error/disabled — written by
 * whatever ingests that source. Every source shows not_configured with a
 * null row until Milestone 3's connector v2 endpoints actually write here;
 * the frontend renders that as a real setup state, not an error.
 */
class IntegrationsController extends Controller
{
    /** GET /sites/{site}/integrations */
    public function index(Request $request)
    {
        $site = $this->site($request);

        $existing = DataSourceConnection::where('site_id', $site->id)
            ->get()
            ->keyBy('source');

        $sources = collect(DataSourceConnection::SOURCES)->map(function (string $source) use ($existing) {
            $row = $existing->get($source);

            return [
                'source'              => $source,
                'state'               => $row->state ?? 'not_configured',
                'label'                => $row->label ?? null,
                'last_attempted_at'    => $row?->last_attempted_at?->toIso8601String(),
                'last_successful_at'   => $row?->last_successful_at?->toIso8601String(),
                'latest_data_at'       => $row?->latest_data_at?->toIso8601String(),
                'freshness'            => $row->freshness ?? 'unknown',
                'last_error_code'      => $row->last_error_code ?? null,
                'last_error_message'   => $row->last_error_message ?? null,
            ];
        });

        return response()->json(['sources' => $sources->values()]);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
