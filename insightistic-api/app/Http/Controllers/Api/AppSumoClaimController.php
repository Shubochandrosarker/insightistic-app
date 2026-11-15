<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSumoLicense;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\AppSumoLicenseService;
use DomainException;
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
            'status'    => $this->licenses->isClaimable($row) ? 'active' : $row->license_status,
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

        if (! $this->licenses->isClaimable($row)) {
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

            // MFA must also guard the FIRST claim, not just subsequent
            // logins. The public claim endpoint can only check a password.
            if ($user->hasMfa()) {
                return response()->json([
                    'message' => 'Sign in normally with multi-factor verification, then contact support to attach this AppSumo license.',
                ], 403);
            }

            if ($row->user_id) {
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
            try {
                [$user, $org] = DB::transaction(function () use ($user, $row) {
                $org = $this->licenses->primaryOrganization($user)
                    ?? $this->provisioner->bootstrapOrganization($user, $user->name);

                $this->applyTierPlan($org, $row);
                $this->licenses->linkToOrganization($row->license_key, $user);

                    return [$user->refresh(), $org->refresh()];
                });
            } catch (DomainException $e) {
                return response()->json([
                    'message' => 'This AppSumo license is no longer available for this account.',
                ], 409);
            }

            return response()->json($this->payload($user, $org, $row->refresh(), $user->createToken('spa')->plainTextToken));
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
                    // The buyer typed this email; AppSumo OAuth validates the
                    // license, NOT control of an arbitrary email address.
                    'email_verified_at' => null,
                ]);

                $rawLicenseKey = null;
                $org = $this->provisioner->bootstrapOrganization($user, $name, $rawLicenseKey);

                $this->applyTierPlan($org, $row);
                $this->licenses->linkToOrganization($row->license_key, $user);

                return [$user->refresh(), $org->refresh()];
            });
        } catch (DomainException $e) {
            return response()->json([
                'message' => 'This AppSumo license is no longer available for this account.',
            ], 409);
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
     * Existing customers, including MFA-enabled customers, redeem with their
     * already authenticated Sanctum session. No password-only MFA bypass.
     * This endpoint sits outside tenant middleware because a signed-in user
     * can be waiting for their first owned organization.
     */
    public function claimAuthenticated(Request $request)
    {
        $user = $request->user();
        $abilities = $user?->currentAccessToken()?->abilities ?? [];

        // Even an elevated support-impersonation session cannot move paid
        // AppSumo entitlements or bind a customer license to an account.
        if (in_array('impersonated', $abilities, true)) {
            return response()->json(['message' => 'License claiming is unavailable during impersonation.'], 403);
        }

        if (! $user || $user->status !== 'active' || $user->archived_at !== null) {
            return response()->json(['message' => 'Sign in to an active account first.'], 403);
        }

        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:64'],
        ]);

        if (! AppSumoLicense::query()->where('license_key', $data['license_key'])->exists()) {
            return response()->json(['message' => 'We could not find that AppSumo license.'], 404);
        }

        try {
            [$org, $row] = DB::transaction(function () use ($data, $user) {
                $row = AppSumoLicense::query()
                    ->where('license_key', $data['license_key'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $this->licenses->isClaimable($row)
                    || ($row->user_id !== null && $row->user_id !== $user->id)) {
                    throw new DomainException('AppSumo license is not available.');
                }

                $org = $this->licenses->primaryOrganization($user)
                    ?? $this->provisioner->bootstrapOrganization($user, $user->name);

                if ($row->org_id !== null && $row->org_id !== $org->id) {
                    throw new DomainException('AppSumo license belongs to another organization.');
                }

                $this->applyTierPlan($org, $row);
                $row = $this->licenses->linkToOrganization($row->license_key, $user);

                return [$org->refresh(), $row];
            });

            $plan = Plan::query()->where('slug', $row->plan_slug)->first();

            return response()->json([
                'plan_slug' => $row->plan_slug,
                'sites_limit' => (int) ($plan?->site_limit ?? 0),
                'organization_id' => $org->id,
                'linked' => true,
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'message' => 'This AppSumo license is no longer available for this account.',
            ], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'We could not activate this license right now. Please retry or contact support.',
            ], 500);
        }
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
            // An AppSumo redemption is a paid lifetime entitlement. If the
            // buyer first created an organic account, that account may still
            // be carrying the no-payment trial projection. Clear that
            // projection atomically so the dashboard cannot show a 7-day
            // trial after the license has been activated.
            $org->forceFill([
                'plan_id' => $plan->id,
                'billing_provider' => 'license',
                'status' => 'active',
                'trial_ends_at' => null,
                'trial_plan_slug' => null,
            ])->save();
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
