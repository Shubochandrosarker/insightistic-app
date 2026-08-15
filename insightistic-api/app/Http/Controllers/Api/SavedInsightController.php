<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedInsight;
use App\Models\Site;
use App\Query\EvidenceLedger;
use App\Query\PlanValidator;
use App\Query\QueryEngineException;
use App\Query\SafeWooQueryEngine;
use App\Services\Ask\ChartSpecBuilder;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * CRUD + "run" for Saved Insights (spec: Insightistic VNext P5). A saved
 * insight is a named, reusable `SemanticQueryPlan` — see
 * docs/SAVED-INSIGHTS.md.
 *
 * Every mutating endpoint validates the submitted plan through the exact
 * same `PlanValidator` the query engine and Ask Insightistic use — a saved
 * insight can never store a plan that wouldn't already be accepted by
 * `POST .../analytics/query`. "Run" re-executes that stored plan through
 * `SafeWooQueryEngine` and records to the Analytical Evidence Ledger
 * (`run_type = "query"`, the same path a direct `analytics/query` call
 * uses) — a saved insight is not a second query engine, only a named
 * pointer to a plan this engine already knows how to run.
 */
class SavedInsightController extends Controller
{
    public function __construct(
        private readonly PlanValidator $validator,
        private readonly EvidenceLedger $ledger,
        private readonly ChartSpecBuilder $chartBuilder,
        private readonly SafeWooQueryEngine $engine,
    ) {}

    /** GET /sites/{site}/saved-insights — org-shared insights plus this user's own private ones. */
    public function index(Request $request)
    {
        $site = $this->site($request);
        $userId = $request->user()?->id;

        $insights = SavedInsight::query()
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('scope', '!=', 'private')->orWhere('owner_user_id', $userId))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['saved_insights' => $insights]);
    }

    /** POST /sites/{site}/saved-insights — body: {name, description?, plan, scope?}. */
    public function store(Request $request)
    {
        $site = $this->site($request);
        $userId = $request->user()?->id;

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['message' => 'name is required.', 'code' => 'invalid_name'], 422);
        }

        $scope = $this->validateScope($request->input('scope', 'organization'));
        if ($scope === null) {
            return response()->json(['message' => 'scope must be one of: '.implode(', ', SavedInsight::SCOPES).'.', 'code' => 'invalid_scope'], 422);
        }

        $rawPlan = $request->input('plan', []);
        if (! is_array($rawPlan)) {
            return response()->json(['message' => 'plan must be an object.', 'code' => 'invalid_plan'], 422);
        }

        try {
            $plan = $this->validator->validate($rawPlan);
        } catch (QueryEngineException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        $insight = SavedInsight::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'owner_user_id' => $userId,
            'name' => $name,
            'description' => $request->input('description') ? (string) $request->input('description') : null,
            'semantic_plan_json' => $plan->toArray(),
            'metric_versions_json' => $this->ledger->metricVersionsFor($plan->metricIds),
            'chart_spec_json' => $this->chartBuilder->typeFor($plan),
            'scope' => $scope,
            'thresholds_json' => null,
            'version' => 1,
        ]);

        return response()->json(['saved_insight' => $insight], 201);
    }

    /** GET /sites/{site}/saved-insights/{savedInsight} */
    public function show(Request $request, int $siteId, int $savedInsight)
    {
        $insight = $this->find($request, $savedInsight);

        return response()->json(['saved_insight' => $insight]);
    }

    /** PATCH /sites/{site}/saved-insights/{savedInsight} — body: any of {name, description, scope, plan}. */
    public function update(Request $request, int $siteId, int $savedInsight)
    {
        $insight = $this->find($request, $savedInsight);
        $site = $this->site($request);

        $changes = [];

        if ($request->has('name')) {
            $name = trim((string) $request->input('name'));
            if ($name === '') {
                return response()->json(['message' => 'name is required.', 'code' => 'invalid_name'], 422);
            }
            $changes['name'] = $name;
        }

        if ($request->has('description')) {
            $changes['description'] = $request->input('description') ? (string) $request->input('description') : null;
        }

        if ($request->has('scope')) {
            $scope = $this->validateScope($request->input('scope'));
            if ($scope === null) {
                return response()->json(['message' => 'scope must be one of: '.implode(', ', SavedInsight::SCOPES).'.', 'code' => 'invalid_scope'], 422);
            }
            $changes['scope'] = $scope;
        }

        if ($request->has('plan')) {
            $rawPlan = $request->input('plan');
            if (! is_array($rawPlan)) {
                return response()->json(['message' => 'plan must be an object.', 'code' => 'invalid_plan'], 422);
            }

            try {
                $plan = $this->validator->validate($rawPlan);
            } catch (QueryEngineException $e) {
                return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
            }

            $changes['semantic_plan_json'] = $plan->toArray();
            $changes['metric_versions_json'] = $this->ledger->metricVersionsFor($plan->metricIds);
            $changes['chart_spec_json'] = $this->chartBuilder->typeFor($plan);
            // The plan's meaning changed — bump so a client can tell this
            // isn't the same definition it last saw, mirroring how
            // MetricDefinition::$version signals a formula/source change.
            $changes['version'] = $insight->version + 1;
        }

        if ($changes !== []) {
            $insight->update($changes);
        }

        return response()->json(['saved_insight' => $insight->fresh()]);
    }

    /** DELETE /sites/{site}/saved-insights/{savedInsight} */
    public function destroy(Request $request, int $siteId, int $savedInsight)
    {
        $insight = $this->find($request, $savedInsight);
        $insight->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /sites/{site}/saved-insights/{savedInsight}/run — re-executes
     * the stored plan now and records a fresh EvidenceRun. Never returns a
     * cached/stale result; every call is a real query.
     */
    public function run(Request $request, Tenancy $tenancy, int $siteId, int $savedInsight)
    {
        $insight = $this->find($request, $savedInsight);
        $site = $this->site($request);
        $userId = $request->user()?->id;

        try {
            $result = $this->engine->run($insight->semantic_plan_json, $site, $tenancy->organization()->id);
        } catch (QueryEngineException $e) {
            $this->ledger->recordFailure($site, $userId, 'query', $insight->semantic_plan_json, $e);

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $this->statusFor($e->errorCode));
        }

        $run = $this->ledger->recordSuccess($site, $userId, 'query', $result);
        $insight->update(['last_run_at' => now()]);

        return response()->json([
            ...$result->toArray(),
            'chart' => $this->chartBuilder->build($result),
            'evidence_run_id' => $run->id,
            'saved_insight_id' => $insight->id,
        ]);
    }

    private function find(Request $request, int $id): SavedInsight
    {
        $site = $this->site($request);
        $userId = $request->user()?->id;

        $insight = SavedInsight::query()->where('site_id', $site->id)->find($id);

        // A private insight belonging to someone else 404s exactly like it
        // doesn't exist for this request — same convention as
        // EvidenceController's cross-org 404, never a 403 that would
        // confirm something exists.
        abort_if(! $insight || ! $insight->visibleTo((int) $userId), 404, 'Saved insight not found.');

        return $insight;
    }

    private function validateScope(mixed $scope): ?string
    {
        return in_array($scope, SavedInsight::SCOPES, true) ? $scope : null;
    }

    private function statusFor(string $code): int
    {
        return match ($code) {
            'concurrency_limit_exceeded' => 429,
            'timeout' => 504,
            'read_only_identity_not_configured' => 503,
            default => 422,
        };
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
