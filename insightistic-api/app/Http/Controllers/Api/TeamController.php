<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\InviteMail;
use App\Models\Site;
use App\Models\SiteUserAccess;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function index()
    {
        $org = $this->tenancy->organization();

        $members = $org->users()->get(['users.id', 'name', 'email', 'status'])->map(function ($u) use ($org) {
            $role = $u->pivot->role;
            $sites = $role === 'client_viewer'
                ? SiteUserAccess::where('user_id', $u->id)
                    ->whereIn('site_id', $org->sites()->pluck('id'))
                    ->pluck('site_id')
                : [];
            return [
                'id'      => $u->id,
                'name'    => $u->name,
                'email'   => $u->email,
                'status'  => $u->status,
                'role'    => $role,
                'is_owner'=> $u->id === $org->owner_user_id,
                'sites'   => $sites,
            ];
        });

        return response()->json(['members' => $members]);
    }

    public function invite(Request $request)
    {
        $org   = $this->tenancy->organization();
        $actor = $request->user();

        $data = $request->validate([
            'email'     => ['required', 'email', 'max:190'],
            'role'      => ['required', 'in:admin,analyst,client_viewer'],
            'name'      => ['nullable', 'string', 'max:120'],
            'site_ids'  => ['array'],
            'site_ids.*'=> ['integer'],
        ]);

        // Only an owner may grant the admin role.
        if ($data['role'] === 'admin' && $actor->roleIn($org) !== 'owner') {
            return response()->json(['message' => 'Only the owner can add admins.', 'code' => 'forbidden_role'], 403);
        }

        // client_viewer must be scoped to at least one of this org's sites.
        $siteIds = [];
        if ($data['role'] === 'client_viewer') {
            $siteIds = Site::whereIn('id', $data['site_ids'] ?? [])->pluck('id')->all(); // org-scoped
            if (empty($siteIds)) {
                return response()->json(['message' => 'A client viewer needs at least one valid site.', 'code' => 'sites_required'], 422);
            }
        }

        // Enforce plan user limit.
        $limit = $org->plan?->user_limit ?? 1;
        if ($org->users()->count() >= $limit) {
            return response()->json(['message' => "User limit reached for your plan ({$limit}).", 'code' => 'user_limit_reached'], 402);
        }

        $user = User::where('email', $data['email'])->first();

        if ($user && $org->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'That person is already in this organization.'], 409);
        }

        // New users are created in an "invited" state with a random password.
        // Credential delivery / set-password lands with the auth email flow (Week 6).
        if (! $user) {
            $user = User::create([
                'name'     => $data['name'] ?? Str::before($data['email'], '@'),
                'email'    => $data['email'],
                'password' => Str::random(40),
                'status'   => 'invited',
            ]);
        }

        DB::transaction(function () use ($org, $user, $data, $siteIds) {
            $org->users()->attach($user->id, ['role' => $data['role']]);
            foreach ($siteIds as $sid) {
                SiteUserAccess::firstOrCreate(
                    ['site_id' => $sid, 'user_id' => $user->id],
                    ['role' => 'client_viewer'],
                );
            }
        });

        // For brand-new users, email a set-password (accept-invite) link.
        if ($user->wasRecentlyCreated) {
            try {
                $token = PasswordBroker::broker()->createToken($user);
                $acceptUrl = rtrim(config('insightistic.app_url'), '/')
                    . '/accept-invite?token=' . $token . '&email=' . urlencode($user->email);
                Mail::to($user->email)->send(new InviteMail($user, $org, $acceptUrl, $data['role']));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'member' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $data['role'],
                'sites' => $siteIds,
            ],
            'note' => $user->wasRecentlyCreated ? 'Invite email sent with a set-password link.' : null,
        ], 201);
    }

    public function updateRole(Request $request, int $user)
    {
        $org = $this->tenancy->organization();

        if ($user === $org->owner_user_id) {
            return response()->json(['message' => "The organization owner's role can't be changed.", 'code' => 'owner_locked'], 422);
        }

        $data = $request->validate(['role' => ['required', 'in:admin,analyst,client_viewer']]);

        if (! $org->users()->where('users.id', $user)->exists()) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $org->users()->updateExistingPivot($user, ['role' => $data['role']]);

        return response()->json(['status' => 'updated', 'user_id' => $user, 'role' => $data['role']]);
    }

    public function remove(int $user)
    {
        $org = $this->tenancy->organization();

        if ($user === $org->owner_user_id) {
            return response()->json(['message' => "The organization owner can't be removed.", 'code' => 'owner_locked'], 422);
        }

        if (! $org->users()->where('users.id', $user)->exists()) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        DB::transaction(function () use ($org, $user) {
            $org->users()->detach($user);
            SiteUserAccess::where('user_id', $user)
                ->whereIn('site_id', $org->sites()->pluck('id'))
                ->delete();
        });

        return response()->json(['status' => 'removed', 'user_id' => $user]);
    }
}
