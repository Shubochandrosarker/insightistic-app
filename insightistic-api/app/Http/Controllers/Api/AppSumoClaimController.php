<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSumoLicense;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\AppSumoLicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * AppSumo buyer claim (the final step of the OAuth loop).
 *
 * AppSumoOAuthController upserts the appsumo_licenses row and redirects the
 * buyer to the SPA claim page with ?license=<key>; this endpoint turns that
 * key into a real account. No auth middleware — the license key plus the
 * password the caller types are the only credentials; a Sanctum token is
 * minted on success. Both endpoints are throttled (routes).
 */
class AppSumoClaimController extends Controller
{
    public function __construct(
        private AppSumoLicenseService $licenses,
        private AccountProvisioner $provisioner,
    ) {}

    /**
     * GET /appsumo/claim/{license_key} — SPA pre-check so the page can show
     * the purchased tier and whether the key was already claimed.
     */
    public function show(string $licenseKey)
    {
        $row = AppSumoLicense::query()->where('license_key', $licenseKey)->first();

        if (! $row) {
            return response()->json(['message' => 'We could not find that AppSumo license.'], 404);
        }

        return response()->json([
            'status'    => $row->license_status,
            'tier'      => $row->tier,
            'plan_slug' => $row->plan_slug,
            'linked'    => $row->user_id !== null,
        ]);
    }

    /**
     * POST /appsumo/claim {license_key, email, password, name?}
     *
     * Three paths: brand-new email → create user + org + link; existing email
     * → password is the login (the license binds to their primary org); key
     * already linked → password re-verification issues a fresh session token.
     */
    public function claim(Request $request)
    {
        // Same normalize-before-validate as AuthController: User's email
        // mutator lowercases on save, so uniqueness checks must compare
        // lowercased input or "Foo@x.com" and "foo@x.com" diverge.
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:64'],
            'email'       => ['required', 'email', 'max:190'],
            // No `confirmed` — the claim form has a single password field.
            'password'    => ['required', 'string', Password::min(8)],
            'name'        => ['nullable', 'string', 'max:120'],
        ]);

        $row = AppSumoLicense::query()->where('license_key', $data['license_key'])->first();

        if (! $row) {
            return response()->json([
                'message' => 'We could not find that AppSumo license. Please double-check the link from your purchase confirmation.',
            ], 404);
        }

        if ($row->license_status !== 'active') {
            return response()->json([
                'message' => 'This AppSumo license is not active. Contact AppSumo support if you believe this is a mistake.',
            ], 422);
        }

        $user = User::query()->where('email', $data['email'])->first();

        // A claimed key binds to exactly one account: any other email — an
        // existing account OR a brand-new one — must not reach the link
        // paths below (a fresh account would otherwise rebind the
        // entitlement away from its owner).
        if ($row->user_id && (! $user || $row->user_id !== $user->id)) {
            return response()->json([
                'message' => 'This license has already been claimed with a different email address.',
            ], 422);
        }

        if ($user) {
            if (! Hash::check($data['password'], $user->password)) {
                return response()->json([
                    'message' => 'That email already has an Insightistic account, but the password does not match. Try signing in instead.',
                ], 422);
            }

            // Account-state gates mirror AuthController::login — the claim
            // endpoint must not be a side door around invites or archival.
            if ($user->status === 'invited') {
                return response()->json([
                    'message' => 'Accept your invite first — check your email for the invite link.',
                ], 403);
            }

            if ($user->status !== 'active' || $user->archived_at !== null) {
                return response()->json([
                    'message' => 'This account has been disabled. Contact your organization owner or support.',
                ], 403);
            }

            if ($row->user_id) {
                // Already claimed by this very user — re-login. MFA accounts
                // go through the normal login (its challenge flow) so two
                // factor is never bypassed by the license key.
                if ($user->hasMfa()) {
                    return response()->json([
                        'message' => 'This license is already active on your account. Please sign in normally — extra sign-in verification is enabled.',
                    ], 403);
                }

                $token = $user->createToken('spa')->plainTextToken;

                return response()->json($this->payload(
                    $user,
                    $this->licenses->primaryOrganization($user),
                    $row,
                    $token,
                ));
            }

            // Existing account, unclaimed license: bind it to their primary
            // org (provisioning one first if they somehow have none).
            [$user, $org] = DB::transaction(function () use ($user, $row) {
                $org = $this->licenses->primaryOrganization($user)
                    ?? $this->provisioner->bootstrapOrganization($user, $user->name);

                $this->applyTierPlan($org, $row);
                $this->licenses->linkToOrganization($row->license_key, $user);

                return [$user->refresh(), $org->refresh()];
            });

            return response()->json($this->payload($user, $org, $row, $user->createToken('spa')->plainTextToken));
        }

        // New account: create user + org, apply the tier plan, bind the key.
        try {
            [$user, $org] = DB::transaction(function () use ($data, $row) {
                $name = trim((string) ($data['name'] ?? ''));

                if ($name === '') {
                    $name = ucfirst((string) Str::of($data['email'])->before('@'));
                }

                $user = User::create([
                    'name'              => $name,
                    'email'             => $data['email'],
                    'password'          => $data['password'], // hashed cast
                    'status'            => 'active',
                    // OAuth-verified buyer: the purchase already proves the
                    // address, so no verification email loop is needed.
                    'email_verified_at' => now(),
                ]);

                $rawLicenseKey = null;
                $org = $this->provisioner->bootstrapOrganization($user, $name, $rawLicenseKey);

                $this->applyTierPlan($org, $row);
                $this->licenses->linkToOrganization($row->license_key, $user);

                return [$user->refresh(), $org->refresh()];
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'We could not complete the claim right now. Please try again or contact support.',
            ], 500);
        }

        return response()->json(
            $this->payload($user, $org, $row, $user->createToken('spa')->plainTextToken),
            201,
        );
    }

    /**
     * Point the org at the tier's lifetime plan (appsumo_solo/studio/agency).
     * The org-level `licenses` row projection happens inside
     * AppSumoLicenseService::applyEntitlements, called by linkToOrganization.
     */
    private function applyTierPlan(Organization $org, AppSumoLicense $row): void
    {
        if (! $row->plan_slug) {
            return;
        }

        $plan = Plan::query()->where('slug', $row->plan_slug)->first();

        if ($plan) {
            $org->forceFill(['plan_id' => $plan->id])->save();
        }
    }

    /** Shared success payload for all three claim paths. */
    private function payload(User $user, ?Organization $org, AppSumoLicense $row, string $token): array
    {
        $plan = $row->plan_slug ? Plan::query()->where('slug', $row->plan_slug)->first() : null;

        return [
            'token'       => $token,
            'user'        => $user->only(['id', 'name', 'email', 'is_super_admin']),
            'org'         => $org?->only(['id', 'name', 'slug', 'status']),
            'plan_slug'   => $row->plan_slug,
            // Seeded plans carry site_limit from commercial.appsumo_plans;
            // the config value is the fallback if a plan row is missing.
            'sites_limit' => (int) ($plan?->site_limit
                ?? config("commercial.appsumo_plans.{$row->plan_slug}.sites", 0)),
        ];
    }
}
