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
        ]);

        $rawLicenseKey = null;

        // If this is the designated platform owner's email (SUPER_ADMIN_EMAIL),
        // grant super admin on first signup so /admin is reachable without a
        // separate manual `artisan insightistic:make-super-admin` step.
        $superAdminEmail = strtolower(trim((string) config('insightistic.super_admin_email', '')));
        $isSuperAdmin    = $superAdminEmail !== '' && $data['email'] === $superAdminEmail;

        [$user, $org] = DB::transaction(function () use ($data, $isSuperAdmin, &$rawLicenseKey) {
            $user = User::create([
                'name'           => $data['name'],
                'email'          => $data['email'],
                'password'       => $data['password'], // hashed cast
                'status'         => 'active',
                'is_super_admin' => $isSuperAdmin,
            ]);

            $org = $this->provisioner->bootstrapOrganization($user, $data['organization_name'], $rawLicenseKey);

            return [$user, $org];
        });

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
                    'status'   => 'active',  // activates invited accounts
                ])->save();
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

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'token'         => $token,
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
}
