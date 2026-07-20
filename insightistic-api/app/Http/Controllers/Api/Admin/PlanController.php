<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Organization;
use App\Models\Plan;
use App\Services\AuditLogger;
use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plan / feature registry editor (spec §13.7). This — not the marketing
 * site's hardcoded pricing copy or the WordPress plugin's own constants —
 * is meant to become the one place plans are actually defined; the "one
 * public plan source" spec §13.7 also asks for (a generated cached
 * manifest the marketing site/app pricing pages consume) is real follow-up
 * work, not something this controller does by itself.
 */
class PlanController extends AdminController
{
    public function __construct(private AuditLogger $audit) {}

    public function index()
    {
        return response()->json([
            'plans' => Plan::withCount('organizations')->orderBy('sort_order')->orderBy('price_monthly')->get(),
        ]);
    }

    public function show(int $id)
    {
        $plan = Plan::withCount('organizations')->findOrFail($id);

        return response()->json([
            'plan'                 => $plan,
            'sample_organizations' => Organization::where('plan_id', $id)->limit(10)->get(['id', 'name', 'status']),
        ]);
    }

    /**
     * GET /admin/plans/{id}/impact — "grandfathering/migration preview
     * before changing current subscribers" (spec §13.7). Read-only; call
     * this before update() to see who an edit would actually affect.
     */
    public function impact(int $id)
    {
        Plan::findOrFail($id);

        return response()->json([
            'subscriber_count'     => Organization::where('plan_id', $id)->count(),
            'sample_organizations' => Organization::where('plan_id', $id)->limit(10)->get(['id', 'name', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $plan = Plan::create($data);
        $this->audit->log($request, 'plan.create', 'Plan', $plan->id, null, $plan->toArray());

        return response()->json(['plan' => $plan], 201);
    }

    public function update(Request $request, int $id)
    {
        $plan = Plan::findOrFail($id);
        $before = $plan->toArray();

        $data = $this->validated($request, $plan->id);
        $plan->update($data);

        $this->audit->log($request, 'plan.update', 'Plan', $plan->id, $before, $plan->fresh()->toArray());

        return response()->json(['plan' => $plan->fresh()]);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'                    => ['required', 'string', 'max:100'],
            'slug'                    => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($ignoreId)],
            'price_monthly'           => ['required', 'integer', 'min:0'],
            'price_yearly'            => ['required', 'integer', 'min:0'],
            'stripe_price_id_monthly' => ['nullable', 'string', 'max:100'],
            'stripe_price_id_yearly'  => ['nullable', 'string', 'max:100'],
            'site_limit'              => ['required', 'integer', 'min:0'],
            'user_limit'              => ['required', 'integer', 'min:0'],
            'ai_insight_limit'        => ['required', 'integer', 'min:0'],
            'report_limit'            => ['required', 'integer', 'min:0'],
            'white_label_enabled'     => ['sometimes', 'boolean'],
            'custom_domain_enabled'   => ['sometimes', 'boolean'],
            'features_json'           => ['sometimes', 'array'],
            'features_json.*'         => [Rule::in(LicenseService::FEATURES)],
            'is_active'               => ['sometimes', 'boolean'],
            'is_featured'             => ['sometimes', 'boolean'],
            'sort_order'              => ['sometimes', 'integer'],
        ]);
    }
}
