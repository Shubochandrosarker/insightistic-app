<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AiInsight;
use App\Models\ClientReport;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SyncLog;
use App\Models\UsageCounter;
use App\Services\AuditLogger;
use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationController extends AdminController
{
    public function __construct(
        private LicenseService $licenses,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = Organization::query()
            ->with(['plan:id,name,slug', 'owner:id,name,email'])
            ->withCount(['sites', 'users']);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }
        if ($s = $request->query('search')) {
            $query->where(function ($w) use ($s, $like) {
                $w->where('name', $like, "%$s%")
                    ->orWhere('slug', $like, "%$s%")
                    ->orWhereHas('owner', fn ($o) => $o->where('email', $like, "%$s%"));
            });
        }
        if ($plan = $request->query('plan')) {
            $query->whereHas('plan', fn ($p) => $p->where('slug', $plan));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $this->applySort($query, $request, ['created_at', 'name', 'status'], 'created_at');

        return response()->json($query->paginate($this->perPage($request)));
    }

    /** POST /admin/organizations — provision a tenant around an existing user. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:150'],
            'owner_user_id'  => ['nullable', 'integer', 'exists:users,id'],
            'owner_email'    => ['required_without:owner_user_id', 'nullable', 'email'],
            'plan'           => ['nullable', 'string', 'exists:plans,slug'],
            'status'         => ['nullable', 'in:trialing,active,past_due,suspended,canceled'],
            'trial_ends_at'  => ['required_if:status,trialing', 'nullable', 'date'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $ownerId = $data['owner_user_id'] ?? \App\Models\User::where('email', strtolower(trim((string) $data['owner_email'])))->value('id');
        abort_unless($ownerId, 422, 'Choose an existing user as the organization owner.');

        $org = DB::transaction(function () use ($data, $ownerId) {
            $planId = ! empty($data['plan']) ? Plan::where('slug', $data['plan'])->value('id') : null;
            $base = Str::slug($data['name']) ?: 'organization';
            $slug = $base;
            $suffix = 1;
            while (Organization::where('slug', $slug)->exists()) {
                $slug = $base . '-' . $suffix++;
            }

            $org = Organization::create([
                'name' => $data['name'],
                'slug' => $slug,
                'owner_user_id' => $ownerId,
                'plan_id' => $planId,
                'status' => $data['status'] ?? 'active',
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
            ]);
            $org->users()->attach($ownerId, ['role' => 'owner']);
            UsageCounter::withoutGlobalScope('organization')->firstOrCreate(
                ['organization_id' => $org->id, 'period' => now()->format('Y-m')],
                ['sites_connected' => 0],
            );

            $licenseKey = $this->licenses->issue($org->fresh('plan'));
            return [$org->fresh(['plan', 'owner']), $licenseKey];
        });

        [$org, $licenseKey] = $org;
        $this->audit->log($request, 'organization.create', 'Organization', $org->id, null, ['name' => $org->name, 'owner_user_id' => $org->owner_user_id, 'plan_id' => $org->plan_id]);

        return response()->json([
            'organization' => $org,
            'license_key' => $licenseKey,
            'notice' => 'Organization created. The raw plugin license is shown once only.',
        ], 201);
    }

    public function show(int $id)
    {
        $org = Organization::with(['plan', 'owner:id,name,email,last_login_at', 'subscription'])
            ->withCount(['sites', 'users'])
            ->findOrFail($id);

        $sites = Site::withoutGlobalScope('organization')->where('organization_id', $id)
            ->get(['id', 'name', 'domain', 'connection_status', 'last_sync_at']);

        return response()->json([
            'organization' => $org,
            'owner'        => $org->owner,
            'plan'         => $org->plan,
            'subscription' => $org->subscription,
            'sites'        => $sites,
            'members'      => $org->users()->get(['users.id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role]),
            'usage'        => $org->currentUsage,
            'recent_sync_logs' => SyncLog::whereIn('site_id', $sites->pluck('id'))
                ->latest('id')->limit(10)->get(['id', 'site_id', 'job', 'status', 'records', 'created_at']),
            'recent_reports' => ClientReport::withoutGlobalScope('organization')->where('organization_id', $id)
                ->latest('id')->limit(10)->get(['id', 'site_id', 'title', 'report_type', 'created_at']),
            'ai_insights_count' => AiInsight::withoutGlobalScope('organization')->where('organization_id', $id)->count(),
        ]);
    }

    /** Edit name, plan, status, and/or internal notes. */
    public function update(Request $request, int $id)
    {
        $org = Organization::findOrFail($id);
        $before = $org->only(['name', 'plan_id', 'status', 'internal_notes']);

        $data = $request->validate([
            'name'           => ['nullable', 'string', 'max:150'],
            'plan'           => ['nullable', 'string', 'exists:plans,slug'],
            'status'         => ['nullable', 'in:trialing,active,past_due,suspended,canceled'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (! empty($data['name'])) {
            $org->name = $data['name'];
        }
        if (! empty($data['plan'])) {
            $org->plan_id = Plan::where('slug', $data['plan'])->value('id');
        }
        if (! empty($data['status'])) {
            $org->status = $data['status'];
        }
        if ($request->has('internal_notes')) {
            $org->internal_notes = $data['internal_notes'];
        }
        $org->save();

        // Without this, a license's persisted status/plan_id/activation_limit
        // only self-heals the next time something reads it (dashboard Licenses
        // tab, plugin validate/activate) — this org's licenses would report
        // stale entitlements to anything reading them in between.
        if (! empty($data['plan']) || ! empty($data['status'])) {
            $this->licenses->syncForOrganization($org->fresh());
        }

        $this->audit->log($request, 'organization.update', 'Organization', $org->id, $before, $org->only(['name', 'plan_id', 'status', 'internal_notes']));

        return response()->json(['organization' => $org->fresh(['plan'])]);
    }

    /**
     * POST /admin/organizations/{id}/archive — reversible. Sites/licenses
     * are left untouched (still queryable, still enforce entitlements as
     * normal); archiving only hides the org from default admin listings and
     * is a signal, not an access change. A separate, more destructive
     * "suspend" already exists via update(status: suspended) for actually
     * cutting off access.
     */
    public function archive(Request $request, int $id)
    {
        $org = Organization::findOrFail($id);
        $org->archived_at = now();
        $org->save();

        $this->audit->log($request, 'organization.archive', 'Organization', $org->id, ['archived_at' => null], ['archived_at' => $org->archived_at->toIso8601String()]);

        return response()->json(['organization' => $org->only(['id', 'archived_at'])]);
    }

    /** POST /admin/organizations/{id}/restore */
    public function restore(Request $request, int $id)
    {
        $org = Organization::findOrFail($id);
        $org->archived_at = null;
        $org->save();

        $this->audit->log($request, 'organization.restore', 'Organization', $org->id, null, ['archived_at' => null]);

        return response()->json(['organization' => $org->only(['id', 'archived_at'])]);
    }
}
