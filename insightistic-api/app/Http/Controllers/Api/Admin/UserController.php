<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class UserController extends AdminController
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = User::query()
            ->select(['id', 'name', 'email', 'status', 'is_super_admin', 'provider', 'last_login_at', 'archived_at', 'created_at'])
            ->withCount('organizations');

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }
        if ($s = $request->query('search')) {
            $query->where(fn ($w) => $w->where('name', $like, "%$s%")->orWhere('email', $like, "%$s%"));
        }
        if ($request->filled('super_admin')) {
            $query->where('is_super_admin', $request->boolean('super_admin'));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($orgId = $request->query('organization_id')) {
            $query->whereHas('organizations', fn ($o) => $o->where('organizations.id', $orgId));
        }
        if ($role = $request->query('role')) {
            $query->whereHas('organizations', fn ($o) => $o->where('organization_users.role', $role));
        }

        $this->applySort($query, $request, ['created_at', 'name', 'last_login_at'], 'created_at');

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function show(int $id)
    {
        $user = User::select(['id', 'name', 'email', 'status', 'is_super_admin', 'provider', 'avatar_url', 'last_login_at', 'email_verified_at', 'archived_at', 'created_at'])
            ->findOrFail($id);

        return response()->json([
            'user'          => $user,
            'organizations' => $user->organizations()->get(['organizations.id', 'name', 'slug', 'status'])
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'slug' => $o->slug, 'status' => $o->status, 'role' => $o->pivot->role]),
            'owns' => $user->ownsOrganizations()->get(['id', 'name', 'slug']),
        ]);
    }

    /**
     * Enable / disable a user. Super-admin elevation is intentionally NOT
     * exposed here — see promote()/demote() below, which carry the
     * last-super-admin protection this endpoint doesn't need.
     */
    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        $before = $user->only(['status']);

        $data = $request->validate([
            'status' => ['required', 'in:active,disabled,suspended'],
        ]);

        if ($data['status'] !== 'active' && $user->is_super_admin) {
            $this->guardLastSuperAdmin($user);
        }

        $user->status = $data['status'];
        $user->save();

        // Login now rejects non-active accounts, but a token issued before
        // this change would otherwise keep working — revoke it immediately
        // so a disable/suspend takes effect on already-open sessions too.
        if ($data['status'] !== 'active') {
            $user->tokens()->delete();
        }

        $this->audit->log($request, 'user.status_change', 'User', $user->id, $before, $data);

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'status', 'is_super_admin'])]);
    }

    /** POST /admin/users/{id}/promote — grant super admin. */
    public function promote(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        if ($user->is_super_admin) {
            return response()->json(['user' => $user->only(['id', 'is_super_admin'])]);
        }

        $user->is_super_admin = true;
        $user->save();

        $this->audit->log($request, 'user.promote_super_admin', 'User', $user->id, ['is_super_admin' => false], ['is_super_admin' => true]);

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'is_super_admin'])]);
    }

    /** POST /admin/users/{id}/demote — revoke super admin. Blocked if this would leave zero active super admins. */
    public function demote(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        if (! $user->is_super_admin) {
            return response()->json(['user' => $user->only(['id', 'is_super_admin'])]);
        }

        $this->guardLastSuperAdmin($user);

        $user->is_super_admin = false;
        $user->save();

        $this->audit->log($request, 'user.demote_super_admin', 'User', $user->id, ['is_super_admin' => true], ['is_super_admin' => false]);

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'is_super_admin'])]);
    }

    /** POST /admin/users/{id}/archive */
    public function archive(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        if ($user->is_super_admin) {
            $this->guardLastSuperAdmin($user);
        }

        $user->archived_at = now();
        $user->save();
        $user->tokens()->delete();

        $this->audit->log($request, 'user.archive', 'User', $user->id, ['archived_at' => null], ['archived_at' => $user->archived_at->toIso8601String()]);

        return response()->json(['user' => $user->only(['id', 'archived_at'])]);
    }

    /** POST /admin/users/{id}/restore */
    public function restore(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        $user->archived_at = null;
        $user->save();

        $this->audit->log($request, 'user.restore', 'User', $user->id, null, ['archived_at' => null]);

        return response()->json(['user' => $user->only(['id', 'archived_at'])]);
    }

    /**
     * Refuses to disable/demote/archive the last remaining active super
     * admin — the one lockout scenario spec §13.1 explicitly calls out to
     * prevent. $user is the one about to lose the flag/be deactivated;
     * excluded from its own count so this only blocks when it's the sole
     * remaining one.
     */
    private function guardLastSuperAdmin(User $user): void
    {
        $remaining = User::where('is_super_admin', true)
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->where('id', '!=', $user->id)
            ->count();

        abort_if($remaining === 0, 422, 'This is the last active Super Admin — promote another account first.');
    }
}
