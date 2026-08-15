<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Agency\AgencyRollupService;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * Agency Intelligence (spec: P10) — a cross-site rollup for the
 * authenticated caller's own organization. Deliberately NOT a
 * `sites/{site}/...` route: this is the one analytical surface in the app
 * that spans every site an organization owns at once, mirroring how
 * `GET /api/sites` (list) already sits outside the `site.access`
 * middleware group for the same reason. See docs/AGENCY-INTELLIGENCE.md.
 */
class AgencyController extends Controller
{
    private const ALLOWED_PERIODS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month'];

    public function __construct(private readonly AgencyRollupService $service) {}

    public function overview(Request $request, Tenancy $tenancy)
    {
        $period = $request->query('period', 'last_30_days');
        if (! in_array($period, self::ALLOWED_PERIODS, true)) {
            return response()->json(['message' => 'period must be one of: '.implode(', ', self::ALLOWED_PERIODS).'.', 'code' => 'invalid_period'], 422);
        }

        // $tenancy->organization() is derived from the authenticated
        // user's own membership — there is no request input that could
        // name a different organization's id.
        $rollup = $this->service->rollup($tenancy->organization(), $period);

        return response()->json($rollup);
    }
}
