<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\AccountProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(
        private AccountProvisioner $provisioner,
        private \App\Services\LifecycleMailer $lifecycleMailer,
        private \App\Services\Auth\MfaService $mfa,
        private \App\Services\Auth\LoginNotifier $loginNotifier,
    ) {}

    /**
     * Register a user AND bootstrap their first organization in one step.
     * The account is unusable without an org, so we never split these.
     */
    public function register(Request $request)
    {
        // Normalize before validation so the uniqueness check and the stored
        // row agree — otherwise "Foo@x.com" and "foo@x.com" would pass
        // `unique:users,email` as distinct, then collide once User's email
        // mutator lowercases both on save.
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name'              => ['required', 'string', 'max:120'],
            'email'             => ['required', 'email', 'max:190', 'unique:users,email'],
            'password'          => ['required', 'confirmed', Password::min(8)],
            'organization_name' => ['required', 'string', 'max:120'],
            // Marketing attribution captured client-side (spec §32); the
            // service whitelists every key before storage.
            'attribution'       => ['nullable', 'array'],
            'attribution.first_touch' => ['nullable', 'array'],
            'attribution.last_touch'  => ['nullable', 'array'],
            'marketing_consent' => ['sometimes', 'boolean'],
        ]);
        $attribution = $data['attribution'] ?? null;
        $marketingConsent = (bool) ($data['marketing_consent'] ?? false);
        unset($data['attribution']);
        unset($data['marketing_consent']);

        $rawLicenseKey = null;

        // SECURITY: public registration NEVER grants platform (super admin)
        // privileges — regardless of email address or any env configuration.
        // Super admins are created only via the protected artisan commands
        // (insightistic:super-admin:*) or promoted by an existing super admin
        // through POST /api/admin/users/{id}/promote. See docs/SUPER-ADMIN.md.
        [$user, $org] = DB::transaction(function () use ($data, &$rawLicenseKey) {
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => $data['password'], // hashed cast
                'status'   => 'active',
            ]);

            $org = $this->provisioner->bootstrapOrganization($user, $data['organization_name'], $rawLicenseKey);

            return [$user, $org];
        });

        // Reload so DB-defaulted columns (is_super_admin=false, …) are present
        // on the model — the response must never report null for them.
        $user->refresh();
        $org->forceFill([
            'marketing_consent' => $marketingConsent,
            'marketing_consent_at' => $marketingConsent ? now() : null,
        ])->save();

        // Attribution (spec §32): first/last touch on the org, sanitized copy
        // on the user, then the signup_completed funnel event (spec §33).
        try {
            $analytics = app(\App\Services\Analytics\AttributionService::class);
            $analytics->applyToOrganization(
                $org,
                $attribution['first_touch'] ?? null,
                $attribution['last_touch'] ?? null,
            );
            $clean = $analytics->sanitize($attribution['first_touch'] ?? null);
            if ($clean) {
                $user->attribution_json = $clean;
                $user->save();
            }
            $analytics->event('signup_completed', $org, $user, [
                'utm_source'  => $clean['utm_source'] ?? null,
                'utm_medium'  => $clean['utm_medium'] ?? null,
                'utm_campaign' => $clean['utm_campaign'] ?? null,
            ]);
        } catch (\Throwable $e) {
            report($e); // analytics must never break signup
        }

        $token = $user->createToken('spa')->plainTextToken;

        // Onboarding emails (never let a mail failure break signup).
        try {
            Mail::to($user->email)->send(new WelcomeMail($user, $org));
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            // Trial-started email carries the one-time license key (spec §11).
            $this->lifecycleMailer->send($org->fresh(['plan', 'owner']), 'trial_started', [
                'license_key' => $rawLicenseKey,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'token'        => $token,
            'user'         => $user->only(['id', 'name', 'email', 'is_super_admin']),
            'organization' => $org->only(['id', 'name', 'slug', 'status', 'trial_ends_at']),
            // Shown ONCE. The dashboard onboarding displays + copies it; only a
            // masked version exists anywhere after this response.
            'license_key'  => $rawLicenseKey,
        ], 201);
    }

    /** Send a password reset link (also used for invite acceptance). */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        PasswordBroker::sendResetLink($request->only('email'));

        // Generic response — never reveal whether an email exists.
        return response()->json(['message' => 'If that email exists, a reset link is on its way.']);
    }

    /** Reset password via token (used by both reset + accept-invite pages). */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $status = PasswordBroker::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password, // hashed cast
                    // Accepting an invite activates the account; every other
                    // status keeps its current value. Flipping disabled /
                    // suspended accounts back to active here would let anyone
                    // un-disable themselves straight from the forgot-password
                    // form (the admin disable control must survive a reset).
                    'status'   => $user->status === 'invited' ? 'active' : $user->status,
                ])->save();

                // A reset usually means the old credentials may be
                // compromised — every existing session token dies with them.
                $user->tokens()->delete();
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        return response()->json(['message' => 'Password updated. You can now sign in.']);
    }

    public function login(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! \Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        if ($user->status === 'invited') {
            return response()->json(['message' => 'Accept your invite first — check your email for the invite link.'], 403);
        }

        // Same account-state gate as OAuthController: archived_at is its own
        // kill switch (AdminUserController::archive), independent of status —
        // password login must not be a side door around it.
        if ($user->status !== 'active' || $user->archived_at !== null) {
            return response()->json(['message' => 'This account has been disabled. Contact your organization owner or support.'], 403);
        }

        // MFA: the password alone must not mint a session token. Hand back a
        // short-lived challenge instead; it identifies the user server-side
        // so the second step never has to re-accept a password over the wire.
        if ($user->hasMfa()) {
            $challenge = \Illuminate\Support\Str::random(48);

            \Illuminate\Support\Facades\Cache::put(
                $this->mfaChallengeKey($challenge),
                $user->id,
                now()->addMinutes(5),
            );

            return response()->json([
                'mfa_required' => true,
                'challenge'    => $challenge,
                'expires_in'   => 300,
            ], 200);
        }

        return response()->json($this->issueSession($request, $user));
    }

    /**
     * POST /auth/mfa/challenge — second step of an MFA login.
     *
     * Accepts a TOTP code or an unused recovery code. The challenge is burned
     * on the first attempt regardless of outcome, so it can't be used to
     * brute-force codes; a wrong code means starting the login again.
     */
    public function mfaChallenge(Request $request)
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code'      => ['required', 'string', 'max:20'],
        ]);

        $userId = \Illuminate\Support\Facades\Cache::pull($this->mfaChallengeKey($data['challenge']));

        if (! $userId) {
            return response()->json([
                'message' => 'That sign-in attempt expired. Please sign in again.',
                'code'    => 'challenge_expired',
            ], 422);
        }

        $user = User::find($userId);

        if (! $user || $user->status !== 'active' || $user->archived_at !== null || ! $user->hasMfa()) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        if (! $this->mfa->verify($user, $data['code'])) {
            return response()->json([
                'message' => 'That code is not valid. Please sign in again.',
                'code'    => 'invalid_code',
            ], 422);
        }

        return response()->json($this->issueSession($request, $user));
    }

    /** Shared success payload for both the direct and MFA login paths. */
    private function issueSession(Request $request, User $user): array
    {
        $this->loginNotifier->handle($request, $user);

        return [
            'token'         => $user->createToken('spa')->plainTextToken,
            'user'          => $user->only(['id', 'name', 'email', 'is_super_admin']),
            'mfa_enabled'   => $user->hasMfa(),
            'organizations' => $user->organizations()->get(['organizations.id', 'name', 'slug'])
                ->map(fn ($o) => [
                    'id'   => $o->id,
                    'name' => $o->name,
                    'slug' => $o->slug,
                    'role' => $o->pivot->role,
                ]),
        ];
    }

    private function mfaChallengeKey(string $challenge): string
    {
        return 'mfa_challenge:' . hash('sha256', $challenge);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user'          => $user->only(['id', 'name', 'email', 'is_super_admin']),
            'organizations' => $user->organizations()->get(['organizations.id', 'name', 'slug'])
                ->map(fn ($o) => [
                    'id'   => $o->id,
                    'name' => $o->name,
                    'slug' => $o->slug,
                    'role' => $o->pivot->role,
                ]),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /** Active sessions (personal access tokens) for the signed-in user. */
    public function sessions(Request $request)
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        return response()->json([
            'sessions' => $request->user()->tokens()
                ->orderByDesc('last_used_at')
                ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
                ->map(fn ($t) => [
                    'id'           => $t->id,
                    'name'         => $t->name,
                    'last_used_at' => $t->last_used_at,
                    'created_at'   => $t->created_at,
                    'expires_at'   => $t->expires_at,
                    'current'      => $t->id === $currentId,
                ]),
        ]);
    }

    /** Revoke one session. 404 for ids that are not the caller's own. */
    public function revokeSession(Request $request, int $tokenId)
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['message' => 'Session revoked.']);
    }

    /** Revoke every session except the one making this call. */
    public function revokeOtherSessions(Request $request)
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $count = $request->user()->tokens()
            ->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))
            ->delete();

        return response()->json(['message' => 'Other sessions revoked.', 'revoked' => $count]);
    }
}
