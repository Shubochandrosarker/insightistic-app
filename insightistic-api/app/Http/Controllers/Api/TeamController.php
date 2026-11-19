<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\CreditWallet;
use App\Domain\Billing\EntitlementResolver;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;

use App\Http\Controllers\Controller;
use App\Mail\InviteMail;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteUserAccess;
use App\Models\User;
use App\Support\Tenancy;
use App\Services\UsageService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function __construct(
        private Tenancy $tenancy,
        private CreditWallet $creditWallet,
        private EntitlementResolver $entitlements,
    ) {}

    private function appSumoPlan(Organization $org): ?array
    {
        $resolved = $this->entitlements->resolve($org);
        $slug = $resolved['plan']['slug'] ?? $org->plan?->slug ?? '';

        return config("commercial.appsumo_plans.{$slug}");
    }

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

        $plan = $this->appSumoPlan($org);

        return response()->json([
            'members' => $members,
            'member_add_credit_cost' => $plan['team_member_add_credits'] ?? null,
            'added_member_limit' => ($plan['team_member_limit'] ?? 0) ?: null,
        ]);
    }

    public function invite(Request $request, UsageService $usage)
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

        $chargeReference = 'team-member:' . $org->id . ':' . Str::uuid();
        $createdInTransaction = false;

        try {
            $user = DB::transaction(function () use ($org, $data, $siteIds, $usage, $chargeReference, &$createdInTransaction) {
                // The lock makes the capacity check and membership change one operation.
                $org = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
                $plan = $this->appSumoPlan($org);
                $user = User::where('email', $data['email'])->first();
                if ($user && $org->users()->where('users.id', $user->id)->exists()) {
                    throw new HttpResponseException(response()->json(['message' => 'That person is already in this organization.'], 409));
                }

                if ($user && ($user->archived_at !== null || ! in_array($user->status, ['active', 'invited'], true))) {
                    throw new HttpResponseException(response()->json(['message' => 'That account is not available to invite.'], 422));
                }

                $this->checkMemberCapacity($org, $plan, $usage);

                // Keep creation, credit spend and attachment atomic so a
                // failed wallet spend never leaves an orphan invitation.
                if (! $user) {
                    $user = User::create([
                        'name'     => $data['name'] ?? Str::before($data['email'], '@'),
                        'email'    => $data['email'],
                        'password' => Str::random(40),
                        'status'   => 'invited',
                    ]);
                    $createdInTransaction = true;
                }

                $cost = (int) ($plan['team_member_add_credits'] ?? 0);
                if ($cost > 0) {
                    $this->creditWallet->consume($org, $cost, 'team_member_added', $chargeReference);
                }

                $org->users()->attach($user->id, ['role' => $data['role']]);
                foreach ($siteIds as $sid) {
                    SiteUserAccess::firstOrCreate(
                        ['site_id' => $sid, 'user_id' => $user->id],
                        ['role' => 'client_viewer'],
                    );
                }

                return $user;
            });
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'message' => "Adding a team member needs {$e->expected} Insight Credits. Buy a credit pack or wait for the next monthly allowance.",
                'code' => 'insufficient_credits',
                'credits' => ['needed' => $e->expected, 'available' => $e->available],
            ], 402);
        }

        // Email a set-password (accept-invite) link for brand-new users, and
        // RE-send it when re-inviting an existing pending user: invite tokens
        // expire after an hour, and the 409 above means re-inviting someone
        // who never accepted is the owner's only resend path.
        $sendInvite = $createdInTransaction || $user->status === 'invited';
        if ($sendInvite) {
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
            'note' => $sendInvite ? 'Invite email sent with a set-password link.' : null,
        ], 201);
    }

    private function checkMemberCapacity(Organization $org, ?array $plan, UsageService $usage): void
    {
        if ($plan !== null) {
            $limit = (int) $plan['team_member_limit'];
            if ($limit === 0) {
                return;
            }

            $used = $org->users()->where('users.id', '!=', $org->owner_user_id)->count();
            if ($used >= $limit) {
                throw new HttpResponseException(response()->json([
                    'message' => "Your AppSumo tier allows {$limit} added team members.",
                    'code' => 'appsumo_team_member_limit_reached',
                    'limit' => ['key' => 'team_member_limit', 'max' => $limit, 'used' => $used],
                ], 402));
            }

            return;
        }

        $limit = max(1, $usage->limit($org, 'team_seats'));
        if ($org->users()->count() >= $limit) {
            throw new HttpResponseException(response()->json([
                'message' => "User limit reached for your plan ({$limit}).",
                'code' => 'user_limit_reached',
            ], 402));
        }
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
