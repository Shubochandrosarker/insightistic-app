<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvidenceRun;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Read-only access to the Analytical Evidence Ledger (spec: P2) — the
 * "Evidence drawer" surface: what ran, against which metric versions, how
 * fresh the data was, and whether it succeeded. Tenant scope comes from
 * `BelongsToOrganization` (query-level) plus an explicit site_id match
 * (defense in depth, matching AnalyticsController's own pattern).
 */
class EvidenceController extends Controller
{
    public function index(Request $request)
    {
        $site = $this->site($request);

        $runs = EvidenceRun::query()
            ->where('site_id', $site->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('run_type'), fn ($q, $type) => $q->where('run_type', $type))
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get();

        return response()->json(['runs' => $runs]);
    }

    /**
     * $siteId is unused (the real Site comes from EnsureSiteAccess via
     * $this->site()) but must stay declared: Laravel binds unresolved
     * scalar controller parameters to route parameters *positionally*, not
     * by name, when the controller doesn't consume every route segment —
     * dropping $siteId here would silently bind $run to the site's id
     * instead of the run's. Same class of bug (and same fix) as
     * tests/Feature/NestedRouteBindingTest.php documents for
     * InsightController/AlertController/ReportScheduleController.
     */
    public function show(Request $request, int $siteId, int $run)
    {
        $site = $this->site($request);

        $evidenceRun = EvidenceRun::query()
            ->where('site_id', $site->id)
            ->where('id', $run)
            ->first();

        if (! $evidenceRun) {
            return response()->json(['message' => 'Evidence run not found.'], 404);
        }

        return response()->json(['run' => $evidenceRun]);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
