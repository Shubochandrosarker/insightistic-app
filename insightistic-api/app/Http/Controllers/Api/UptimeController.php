<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProbeUptimeJob;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Services\Uptime\SslInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Uptime API (Phase 12, doc 16). The engine (Probe / IncidentManager /
 * SslInspector / ProbeUptimeJob / scheduler) lives in App\Services\Uptime
 * and the queue; this controller is the gate in front of it and the read
 * surface the Uptime panel consumes.
 *
 * One monitor per site (MVP, DB-level UNIQUE): POST stores CREATE-or-
 * UPDATE the site's single monitor, so the client never has to track an
 * id. Reads are open to anyone with site access like every other analytics
 * surface; configuring the monitor, deleting it (which cascades its whole
 * history) and the immediate check-now are owner/admin like every other
 * queued heavy action.
 *
 * Interval floor (doc 16 plan mapping): the requested interval_minutes is
 * validated 1..60, then clamped UP to the plan's
 * uptime_interval_minutes entitlement when the plan declares one —
 * high-frequency checks are recurring infrastructure cost and must be
 * plan limited. Plans without the key get floor 1 (the migration default
 * of 5 remains the un-requested default).
 */
class UptimeController extends Controller
{
    /** How many incidents the overview and the dedicated endpoint return. */
    private const INCIDENT_LIMIT = 20;

    /** GET /sites/{site}/uptime — overview: monitor + incidents + ssl + availability. */
    public function index(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $monitor = UptimeMonitor::query()->where('site_id', $site->id)->first();

        if ($monitor === null) {
            return response()->json([
                'has_monitor'      => false,
                'monitor'          => null,
                'incidents'        => [],
                'ssl'              => null,
                'availability_pct' => null,
            ]);
        }

        return response()->json([
            'has_monitor'      => true,
            'monitor'          => $this->monitorPayload($monitor),
            'incidents'        => $this->incidentsPayload($monitor->site_id),
            'ssl'              => $this->sslPayload($monitor),
            'availability_pct' => $monitor->availabilityPct(),
        ]);
    }

    /** POST /sites/{site}/uptime/monitors — create or update THE monitor. */
    public function store(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $data = $request->validate([
            'url'              => ['nullable', 'url', 'max:1000'],
            'method'           => ['nullable', 'string', 'in:'.implode(',', UptimeMonitor::METHODS)],
            'expected_status'  => ['nullable', 'integer', 'between:100,599'],
            'interval_minutes' => ['nullable', 'integer', 'between:1,60'],
            'active'           => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('interval_minutes', $data)) {
            // Plan floor: clamp up, never down (doc 16 — intervals are the plan throttle).
            $floor = max(1, $site->organization?->plan?->limit('uptime_interval_minutes') ?: 1);
            $data['interval_minutes'] = max($floor, (int) $data['interval_minutes']);
        }

        $monitor = UptimeMonitor::query()->updateOrCreate(
            ['site_id' => $site->id],
            collect($data)->only(['url', 'method', 'expected_status', 'interval_minutes', 'active'])->all(),
        );

        return response()->json([
            'has_monitor' => true,
            'monitor'     => $this->monitorPayload($monitor->refresh()),
        ], $monitor->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /sites/{site}/uptime/monitors/{monitor} — monitor + history
     * (cascade). The id is resolved manually rather than via implicit model
     * binding, matching this codebase's convention for nested
     * sites/{site}/.../{id} routes (see NestedRouteBindingTest): Laravel
     * fills method parameters POSITIONALLY here, so both the {site} and the
     * {monitor} values must be taken in URI order and the monitor pinned to
     * the site.
     */
    public function destroy(Request $request, int $siteId, int $monitor): JsonResponse
    {
        $model = UptimeMonitor::query()->find($monitor);

        if ($model === null || $model->site_id !== $siteId) {
            return response()->json(['message' => 'Monitor not found.'], 404);
        }

        $model->delete(); // checks + incidents go with it (FK cascade)

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /sites/{site}/uptime/check — check now. Runs the same
     * ProbeUptimeJob inline (dispatchSync): a probe is capped at 10s, so a
     * synchronous answer is within API budget and the caller gets the real
     * result plus the post-record incident state back.
     */
    public function check(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $monitor = UptimeMonitor::query()->where('site_id', $site->id)->first();

        if ($monitor === null) {
            return response()->json(['message' => 'No uptime monitor configured for this site.'], 422);
        }

        ProbeUptimeJob::dispatchSync($monitor->id);

        $monitor->refresh();
        $check = $monitor->lastCheck();
        $open = $monitor->openIncident();

        return response()->json([
            'result'   => $check === null ? null : $this->checkPayload($check),
            'incident' => $open === null ? null : $this->incidentPayload($open),
        ]);
    }

    /** GET /sites/{site}/uptime/incidents — last 20, with duration. */
    public function incidents(Request $request): JsonResponse
    {
        $site = $this->site($request);

        return response()->json([
            'incidents' => $this->incidentsPayload($site->id),
        ]);
    }

    // ------------------------------------------------------------------

    private function monitorPayload(UptimeMonitor $monitor): array
    {
        $last = $monitor->lastCheck();
        $open = $monitor->openIncident();

        return [
            'id'               => $monitor->id,
            'site_id'          => $monitor->site_id,
            // Resolved target — a null stored url really probes https://domain.
            'url'              => $monitor->probeUrl(),
            'method'           => $monitor->method,
            'expected_status'  => (int) $monitor->expected_status,
            'interval_minutes' => (int) $monitor->interval_minutes,
            'active'           => (bool) $monitor->active,
            'last_checked_at'  => $monitor->last_checked_at?->toIso8601String(),
            'last_check'       => $last === null ? null : $this->checkPayload($last),
            'open_incident'    => $open === null ? null : $this->incidentPayload($open),
        ];
    }

    private function checkPayload(UptimeCheck $check): array
    {
        return [
            'status'      => $check->status,
            'status_code' => $check->status_code === null ? null : (int) $check->status_code,
            'response_ms' => $check->response_ms === null ? null : (int) $check->response_ms,
            'error'       => $check->error,
            'checked_at'  => $check->checked_at?->toIso8601String(),
        ];
    }

    private function incidentPayload(UptimeIncident $incident): array
    {
        return [
            'id'               => $incident->id,
            'status'           => $incident->lifecycleStatus(),
            'started_at'       => $incident->started_at?->toIso8601String(),
            'confirmed_at'     => $incident->confirmed_at?->toIso8601String(),
            'recovered_at'     => $incident->recovered_at?->toIso8601String(),
            'cause'            => $incident->cause,
            'status_code'      => $incident->status_code === null ? null : (int) $incident->status_code,
            'duration_seconds' => $incident->durationSeconds(),
        ];
    }

    private function incidentsPayload(int $siteId): array
    {
        return UptimeIncident::query()
            ->where('site_id', $siteId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::INCIDENT_LIMIT)
            ->get()
            ->map(fn (UptimeIncident $incident) => $this->incidentPayload($incident))
            ->all();
    }

    private function sslPayload(UptimeMonitor $monitor): ?array
    {
        if ($monitor->ssl_expires_at === null) {
            return null;
        }

        $expiresAt = Carbon::parse($monitor->ssl_expires_at)->startOfDay();
        $daysLeft = (int) floor(Carbon::now()->startOfDay()->diffInDays($expiresAt, false));

        return [
            'expires_at' => $monitor->ssl_expires_at->toDateString(),
            'days_left'  => $daysLeft,
            'tone'       => $daysLeft < 0 ? 'expired'
                : ($daysLeft < SslInspector::CRITICAL_DAYS ? 'critical'
                : ($daysLeft < SslInspector::WARN_DAYS ? 'warning' : 'ok')),
        ];
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
