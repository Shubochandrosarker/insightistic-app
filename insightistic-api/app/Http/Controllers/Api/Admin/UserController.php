<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends AdminController
{
    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = User::query()
            ->select(['id', 'name', 'email', 'status', 'is_super_admin', 'provider', 'last_login_at', 'created_at'])
            ->withCount('organizations');

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
        $user = User::select(['id', 'name', 'email', 'status', 'is_super_admin', 'provider', 'avatar_url', 'last_login_at', 'email_verified_at', 'created_at'])
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
     * exposed here — it is a backend-only action (artisan command) for safety.
     */
    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', 'in:active,disabled,suspended'],
        ]);

        $user->status = $data['status'];
        $user->save();

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'status', 'is_super_admin'])]);
    }
}
