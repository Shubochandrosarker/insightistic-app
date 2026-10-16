<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AiInsight;
use App\Models\ClientReport;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteUserAccess;
use App\Models\SyncLog;
use App\Models\UsageCounter;
use App\Models\User;
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

        $memberIds = $org->users()->pluck('users.id');
        $siteAccess = SiteUserAccess::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->whereIn('user_id', $memberIds)
            ->get(['user_id', 'site_id'])
            ->groupBy('user_id');

        return response()->json([
            'organization' => $org,
            'owner'        => $org->owner,
            'plan'         => $org->plan,
            'subscription' => $org->subscription,
            'sites'        => $sites,
            'members'      => $org->users()->get(['users.id', 'name', 'email', 'status'])
                ->map(function ($u) use ($siteAccess, $org) {
                    $role = $u->pivot->role;

                    return [
                        'id'       => $u->id,
                        'name'     => $u->name,
                        'email'    => $u->email,
                        'status'   => $u->status,
                        'role'     => $role,
                        'site_ids' => $role === 'client_viewer'
                            ? ($siteAccess->get($u->id, collect())->pluck('site_id')->values()->all())
                            : [],
                        'is_owner' => $u->id === $org->owner_user_id,
                    ];
                }),
            'usage'        => $org->currentUsage,
            'recent_sync_logs' => SyncLog::whereIn('site_id', $sites->pluck('id'))
                ->latest('id')->limit(10)->get(['id', 'site_id', 'job', 'status', 'records', 'created_at']),
            'recent_reports' => ClientReport::withoutGlobalScope('organization')->where('organization_id', $id)
                ->latest('id')->limit(10)->get(['id', 'site_id', 'title', 'report_type', 'created_at']),
            'ai_insights_count' => AiInsight::withoutGlobalScope('organization')->where('organization_id', $id)->count(),
        ]);
    }

    /**
     * Add an existing platform user to an organization and optionally scope
     * a client viewer to selected sites. This is deliberately separate from
     * organization self-service invites: platform admins may connect an
     * existing account without creating a duplicate identity.
     */
    public function addMember(Request $request, int $id)
    {
        $org = Organization::findOrFail($id);
        $data = $request->validate([
            'user_id'  => ['required', 'integer', 'exists:users,id'],
            'role'     => ['required', 'in:admin,analyst,client_viewer'],
            'site_ids' => ['sometimes', 'array'],
            'site_ids.*' => ['integer'],
        ]);
        $user = User::findOrFail($data['user_id']);

        if ($org->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'That user is already a member of this organization.', 'code' => 'member_exists'], 409);
        }

        $requestedSiteIds = $this->normaliseSiteIds($data['site_ids'] ?? []);
        $siteIds = $this->validSiteIds($org->id, $requestedSiteIds);
        if (! $this->sameSiteIds($requestedSiteIds, $siteIds)) {
            return response()->json(['message' => 'site_ids contains sites that do not belong to this organization.', 'code' => 'invalid_site_ids'], 422);
        }
        if ($data['role'] === 'client_viewer' && $siteIds === []) {
            return response()->json(['message' => 'A client viewer needs at least one site.', 'code' => 'sites_required'], 422);
        }

        DB::transaction(function () use ($org, $user, $data, $siteIds) {
            $org->users()->attach($user->id, ['role' => $data['role']]);
            $this->syncSiteAccess($org->id, $user->id, $data['role'], $siteIds);
        });

        $this->audit->log($request, 'organization.member_add', 'Organization', $org->id, null, [
            'user_id' => $user->id,
            'role' => $data['role'],
            'site_ids' => $siteIds,
        ]);

        return response()->json(['member' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'role' => $data['role'],
            'site_ids' => $siteIds,
        ]], 201);
    }

    /** Update a member's role and exact site scope. */
    public function updateMember(Request $request, int $id, int $userId)
    {
        $org = Organization::findOrFail($id);
        $member = $org->users()->where('users.id', $userId)->firstOrFail();

        if ($member->id === $org->owner_user_id || $member->pivot->role === 'owner') {
            return response()->json(['message' => "The organization owner's role can't be changed.", 'code' => 'owner_locked'], 422);
        }

        $data = $request->validate([
            'role' => ['required', 'in:admin,analyst,client_viewer'],
            'site_ids' => ['sometimes', 'array'],
            'site_ids.*' => ['integer'],
        ]);
        $requestedSiteIds = $this->normaliseSiteIds($data['site_ids'] ?? []);
        $siteIds = $this->validSiteIds($org->id, $requestedSiteIds);
        if (! $this->sameSiteIds($requestedSiteIds, $siteIds)) {
            return response()->json(['message' => 'site_ids contains sites that do not belong to this organization.', 'code' => 'invalid_site_ids'], 422);
        }
        if ($data['role'] === 'client_viewer' && $siteIds === []) {
            return response()->json(['message' => 'A client viewer needs at least one site.', 'code' => 'sites_required'], 422);
        }

        $before = ['role' => $member->pivot->role, 'site_ids' => $this->memberSiteIds($org->id, $userId)];
        DB::transaction(function () use ($org, $userId, $data, $siteIds) {
            $org->users()->updateExistingPivot($userId, ['role' => $data['role']]);
            $this->syncSiteAccess($org->id, $userId, $data['role'], $siteIds);
        });

        $this->audit->log($request, 'organization.member_update', 'Organization', $org->id, $before, [
            'user_id' => $userId,
            'role' => $data['role'],
            'site_ids' => $siteIds,
        ]);

        return response()->json(['member' => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'status' => $member->status,
            'role' => $data['role'],
            'site_ids' => $siteIds,
        ]]);
    }

    /** Remove a member and all site scopes belonging to this organization. */
    public function removeMember(Request $request, int $id, int $userId)
    {
        $org = Organization::findOrFail($id);
        $member = $org->users()->where('users.id', $userId)->firstOrFail();

        if ($member->id === $org->owner_user_id || $member->pivot->role === 'owner') {
            return response()->json(['message' => "The organization owner can't be removed.", 'code' => 'owner_locked'], 422);
        }

        DB::transaction(function () use ($org, $userId) {
            $org->users()->detach($userId);
            SiteUserAccess::where('user_id', $userId)
                ->whereIn('site_id', $this->organizationSiteQuery($org->id)->pluck('id'))
                ->delete();
        });

        $this->audit->log($request, 'organization.member_remove', 'Organization', $org->id, ['user_id' => $userId], null);

        return response()->json(['status' => 'removed', 'user_id' => $userId]);
    }

    /** Edit name, plan, status, and/or internal notes. */
    public function update(Request $request, int $id)
    {
        $org = Organization::findOrFail($id);
        $before = $org->only(['name', 'owner_user_id', 'plan_id', 'status', 'trial_ends_at', 'internal_notes']);

        $data = $request->validate([
            'name'           => ['nullable', 'string', 'max:150'],
            'owner_user_id'  => ['nullable', 'integer', 'exists:users,id'],
            'owner_email'    => ['nullable', 'email'],
            'plan'           => ['nullable', 'string', 'exists:plans,slug'],
            'status'         => ['nullable', 'in:trialing,active,past_due,suspended,canceled'],
            'trial_ends_at'  => ['nullable', 'date'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $ownerId = $data['owner_user_id'] ?? null;
        if (! $ownerId && ! empty($data['owner_email'])) {
            $ownerId = \App\Models\User::where('email', strtolower(trim($data['owner_email'])))->value('id');
            abort_unless($ownerId, 422, 'Choose an existing user as the organization owner.');
        }

        if (! empty($data['name'])) {
            $org->name = $data['name'];
        }
        if ($ownerId) {
            $org->owner_user_id = $ownerId;
        }
        if (! empty($data['plan'])) {
            $org->plan_id = Plan::where('slug', $data['plan'])->value('id');
        }
        if (! empty($data['status'])) {
            $org->status = $data['status'];
        }
        if ($request->has('trial_ends_at')) {
            $org->trial_ends_at = $data['trial_ends_at'];
        }
        if ($request->has('internal_notes')) {
            $org->internal_notes = $data['internal_notes'];
        }
        $org->save();

        if ($ownerId) {
            $org->users()->syncWithoutDetaching([$ownerId => ['role' => 'owner']]);
            $org->users()->wherePivot('role', 'owner')->where('users.id', '!=', $ownerId)
                ->get()->each(fn ($user) => $org->users()->updateExistingPivot($user->id, ['role' => 'member']));
        }

        // Without this, a license's persisted status/plan_id/activation_limit
        // only self-heals the next time something reads it (dashboard Licenses
        // tab, plugin validate/activate) — this org's licenses would report
        // stale entitlements to anything reading them in between.
        if (! empty($data['plan']) || ! empty($data['status'])) {
            $this->licenses->syncForOrganization($org->fresh());
        }

        $this->audit->log($request, 'organization.update', 'Organization', $org->id, $before, $org->only(['name', 'owner_user_id', 'plan_id', 'status', 'trial_ends_at', 'internal_notes']));

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

    /** @return array<int> */
    private function validSiteIds(int $organizationId, array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return $this->organizationSiteQuery($organizationId)
            ->whereIn('id', $siteIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<int> */
    private function normaliseSiteIds(array $siteIds): array
    {
        return array_values(array_unique(array_map('intval', $siteIds)));
    }

    private function sameSiteIds(array $requested, array $valid): bool
    {
        sort($requested);
        sort($valid);

        return $requested === $valid;
    }

    private function organizationSiteQuery(int $organizationId)
    {
        return Site::withoutGlobalScope('organization')->where('organization_id', $organizationId);
    }

    /** @return array<int> */
    private function memberSiteIds(int $organizationId, int $userId): array
    {
        return SiteUserAccess::where('user_id', $userId)
            ->whereIn('site_id', $this->organizationSiteQuery($organizationId)->pluck('id'))
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function syncSiteAccess(int $organizationId, int $userId, string $role, array $siteIds): void
    {
        SiteUserAccess::where('user_id', $userId)
            ->whereIn('site_id', $this->organizationSiteQuery($organizationId)->pluck('id'))
            ->delete();

        if ($role !== 'client_viewer') {
            return;
        }

        foreach ($siteIds as $siteId) {
            SiteUserAccess::create(['site_id' => $siteId, 'user_id' => $userId, 'role' => 'client_viewer']);
        }
    }

}
