<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Register a user AND bootstrap their first organization in one step.
     * The account is unusable without an org, so we never split these.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:120'],
            'email'             => ['required', 'email', 'max:190', 'unique:users,email'],
            'password'          => ['required', 'confirmed', Password::min(8)],
            'organization_name' => ['required', 'string', 'max:120'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => $data['password'], // hashed cast
                'status'   => 'active',
            ]);

            $starter = Plan::where('slug', 'starter')->first();

            $org = Organization::create([
                'name'          => $data['organization_name'],
                'slug'          => $this->uniqueSlug($data['organization_name']),
                'owner_user_id' => $user->id,
                'plan_id'       => $starter?->id,
                'status'        => 'trialing',
                'trial_ends_at' => now()->addDays(14),
            ]);

            $org->users()->attach($user->id, ['role' => 'owner']);

            UsageCounter::create([
                'organization_id' => $org->id,
                'period'          => now()->format('Y-m'),
                'sites_connected' => 0,
            ]);

            return [$user, $org];
        });

        [$user, $org] = $result;
        $token = $user->createToken('spa')->plainTextToken;

        // Onboarding email #1 (never let a mail failure break signup).
        try {
            Mail::to($user->email)->send(new WelcomeMail($user, $org));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'token'        => $token,
            'user'         => $user->only(['id', 'name', 'email']),
            'organization' => $org->only(['id', 'name', 'slug', 'status', 'trial_ends_at']),
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
            'user'          => $user->only(['id', 'name', 'email']),
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
            'user'          => $user->only(['id', 'name', 'email']),
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

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 1;
        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
