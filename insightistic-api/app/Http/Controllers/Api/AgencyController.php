<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgencyClient;
use App\Models\Site;
use App\Services\Agency\AgencyPortfolio;
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
 *
 * Phase 13 adds the agency PORTFOLIO (doc 20) on the same surface: per-site
 * health/uptime/search/freshness rows plus the optional client grouping
 * (organization -> clients -> sites). Client CRUD and site assignment are
 * role-gated to owner/admin at the route level; portfolio reads mirror
 * overview()'s access (any member of the org).
 */
class AgencyController extends Controller
{
    private const ALLOWED_PERIODS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month'];

    /** Portfolio `health` filter buckets — thresholds live in AgencyPortfolio::healthState(). */
    private const ALLOWED_HEALTH_FILTERS = ['critical', 'warning', 'healthy'];

    public function __construct(
        private readonly AgencyRollupService $service,
        private readonly AgencyPortfolio $portfolio,
    ) {}

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

    /** Portfolio dashboard rows (doc 20). Read access mirrors overview(). */
    public function portfolio(Request $request, Tenancy $tenancy)
    {
        $health = $request->query('health');
        if ($health !== null && ! in_array($health, self::ALLOWED_HEALTH_FILTERS, true)) {
            return response()->json(['message' => 'health must be one of: '.implode(', ', self::ALLOWED_HEALTH_FILTERS).'.', 'code' => 'invalid_health_filter'], 422);
        }

        $clientId = $request->query('client_id');
        if ($clientId !== null) {
            // BelongsToOrganization's global scope makes a foreign client
            // simply not exist here — findOrFail 404s instead of leaking ids.
            AgencyClient::query()->findOrFail((int) $clientId);
            $clientId = (int) $clientId;
        }

        return response()->json(
            $this->portfolio->portfolio($tenancy->organization(), $clientId, $health),
        );
    }

    /** All the org's clients, each with its assigned-site count. */
    public function index(Tenancy $tenancy)
    {
        $clients = AgencyClient::query()
            ->withCount('sites')
            ->orderBy('name')
            ->get();

        return response()->json(['clients' => $clients]);
    }

    public function store(Request $request)
    {
        $client = AgencyClient::create($this->validateClient($request)); // organization_id auto-filled from tenancy

        return response()->json($client, 201);
    }

    public function update(Request $request, int $client, Tenancy $tenancy)
    {
        $model = $this->findClient($client, $tenancy);
        $model->update($this->validateClient($request));

        return response()->json($model->refresh());
    }

    /**
     * Delete a client. Its sites are UNASSIGNED first (doc 20: dropping a
     * client grouping must never delete the sites) — the FK's nullOnDelete
     * is the database backstop; this makes the intent explicit and keeps
     * the sites' other state untouched.
     */
    public function destroy(int $client, Tenancy $tenancy)
    {
        $model = $this->findClient($client, $tenancy);

        Site::query()->where('client_id', $model->id)->update(['client_id' => null]);
        $model->delete();

        return response()->noContent();
    }

    /**
     * Sync the client's assigned sites: `site_ids` becomes EXACTLY the
     * client's site set. Sites that had this client but are not listed are
     * unassigned; listed ids are assigned, even if they previously sat
     * under a different client (the caller named them explicitly).
     *
     * Every submitted id is validated to belong to the caller's own
     * organization BEFORE anything is written — a foreign site id is a 422,
     * never a silent cross-tenant reassignment.
     */
    public function assignSites(Request $request, int $client, Tenancy $tenancy)
    {
        $model = $this->findClient($client, $tenancy);

        $validated = $request->validate([
            'site_ids'   => ['required', 'array'],
            'site_ids.*' => ['integer'],
        ]);

        $requested = array_values(array_unique(array_map('intval', $validated['site_ids'])));

        $orgSiteIds = Site::query()
            ->where('organization_id', $tenancy->organization()->id)
            ->whereIn('id', $requested)
            ->pluck('id');

        if ($orgSiteIds->count() !== count($requested)) {
            return response()->json([
                'message' => 'site_ids contains sites that do not belong to your organization.',
                'code'    => 'invalid_site_ids',
            ], 422);
        }

        Site::query()
            ->where('client_id', $model->id)
            ->whereNotIn('id', $orgSiteIds)
            ->update(['client_id' => null]);

        if ($orgSiteIds->isNotEmpty()) {
            Site::query()->whereIn('id', $orgSiteIds)->update(['client_id' => $model->id]);
        }

        return response()->json([
            'client_id'   => $model->id,
            'site_ids'    => $orgSiteIds->values()->all(),
            'sites_count' => $orgSiteIds->count(),
        ]);
    }

    /**
     * Tenant-safe client resolution. Route-model binding is deliberately
     * NOT used: SubstituteBindings runs in the `api` group BEFORE the
     * `tenant` middleware, so a bound model is fetched with Tenancy still
     * empty and the BelongsToOrganization global scope inert — the same
     * reason AlertController/AutomationRuleController resolve ids through a
     * scoped find() instead of a type-hinted model. A foreign id is a 404.
     */
    private function findClient(int $clientId, Tenancy $tenancy): AgencyClient
    {
        return AgencyClient::query()
            ->where('organization_id', $tenancy->organization()->id)
            ->findOrFail($clientId);
    }

    /** @return array{name: string, contact_email: string|null, notes: string|null} */
    private function validateClient(Request $request): array
    {
        return $request->validate([
            'name'          => ['required', 'string', 'max:160'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);
    }
}
