<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\MfaFailure;
use App\Services\Auth\MfaService;
use App\Services\Auth\ReauthGuard;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Two-factor enrolment and management (spec Phase 1.4).
 *
 * Enrolment is two-step (start → confirm with a working code) so a failed
 * authenticator scan can never lock a user out of their own account.
 *
 * Every state change here — enabling, disabling, regenerating recovery codes
 * — requires a recent password confirmation, because an attacker holding a
 * live session should not be able to swap the second factor for their own.
 */
class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly ReauthGuard $reauth,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /auth/mfa */
    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'enabled'               => $user->hasMfa(),
            'enrolment_started'     => $user->mfa_secret !== null && ! $user->hasMfa(),
            'confirmed_at'          => $user->mfa_confirmed_at?->toIso8601String(),
            'recovery_codes_left'   => $this->mfa->unusedRecoveryCodeCount($user),
            // Owners and super admins are the accounts the spec singles out.
            'recommended'           => $user->isSuperAdmin() || $this->ownsAnyOrganization($user),
            'reauth_confirmed'      => $this->reauth->confirmedRecently($request),
        ]);
    }

    /** POST /auth/mfa/setup — behind reauth */
    public function setup(Request $request)
    {
        $user = $request->user();

        if ($user->hasMfa()) {
            return response()->json([
                'message' => 'Two-factor authentication is already enabled. Disable it first to re-enrol.',
                'code'    => 'already_enabled',
            ], 409);
        }

        return response()->json($this->mfa->beginEnrolment($user));
    }

    /** POST /auth/mfa/confirm — behind reauth */
    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();

        try {
            $codes = $this->mfa->confirmEnrolment($user, $data['code']);
        } catch (MfaFailure $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        $this->audit->log($request, 'mfa.enabled', 'user', $user->id);

        // The only time these are ever readable — they are stored hashed.
        return response()->json([
            'status'         => 'enabled',
            'recovery_codes' => $codes,
        ]);
    }

    /** DELETE /auth/mfa — behind reauth */
    public function disable(Request $request)
    {
        $user = $request->user();

        if (! $user->hasMfa()) {
            return response()->json(['message' => 'Two-factor authentication is not enabled.', 'code' => 'not_enabled'], 409);
        }

        $this->mfa->disable($user);
        $this->audit->log($request, 'mfa.disabled', 'user', $user->id);

        return response()->json(['status' => 'disabled']);
    }

    /** POST /auth/mfa/recovery-codes — behind reauth */
    public function regenerateRecoveryCodes(Request $request)
    {
        $user = $request->user();

        if (! $user->hasMfa()) {
            return response()->json(['message' => 'Enable two-factor authentication first.', 'code' => 'not_enabled'], 409);
        }

        $codes = $this->mfa->regenerateRecoveryCodes($user);
        $this->audit->log($request, 'mfa.recovery_codes_regenerated', 'user', $user->id);

        return response()->json(['recovery_codes' => $codes]);
    }

    /**
     * POST /auth/reauthenticate
     *
     * Confirms the password (and a TOTP/recovery code when MFA is on), then
     * marks this specific token as recently confirmed for a short window.
     */
    public function reauthenticate(Request $request)
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code'     => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'That password is not correct.', 'code' => 'invalid_password'], 422);
        }

        if ($user->hasMfa() && ! $this->mfa->verify($user, (string) ($data['code'] ?? ''))) {
            return response()->json([
                'message' => 'Enter a valid code from your authenticator app.',
                'code'    => 'invalid_code',
            ], 422);
        }

        $this->reauth->confirm($request);

        return response()->json([
            'status'     => 'confirmed',
            'expires_in' => ReauthGuard::TTL_MINUTES * 60,
        ]);
    }

    private function ownsAnyOrganization($user): bool
    {
        return $user->organizations()->wherePivot('role', 'owner')->exists();
    }
}
