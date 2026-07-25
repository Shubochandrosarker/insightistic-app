<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment, verification and recovery codes (spec Phase 1.4).
 *
 * Uses pragmarx/google2fa rather than a hand-rolled RFC 6238 implementation.
 *
 * Two details that matter for correctness:
 *
 *  - Enrolment is two-step. `beginEnrolment()` writes a secret but leaves
 *    `mfa_confirmed_at` null, and only `confirmEnrolment()` — which requires
 *    a working code — switches MFA on. Enabling on the first write would
 *    lock out anyone whose authenticator failed to scan.
 *
 *  - A used TOTP code is burned for its window. Without that, a code shoulder-
 *    surfed or captured in transit stays valid for the rest of its 30-second
 *    step and can be replayed.
 *
 * Recovery codes are stored as hashes, never plaintext, so a database dump
 * cannot be turned into a working second factor.
 */
class MfaService
{
    /** Accept the adjacent step either side, to tolerate clock drift. */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    /**
     * Start enrolment: mint a secret and return what the user needs to add
     * it to an authenticator. MFA is NOT active until confirmEnrolment().
     *
     * @return array{secret:string,otpauth_uri:string,qr_svg:string}
     */
    public function beginEnrolment(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        $user->mfa_secret = $secret;
        $user->mfa_confirmed_at = null;
        $user->save();

        $uri = $this->google2fa->getQRCodeUrl(
            (string) config('app.name', 'Insightistic'),
            $user->email,
            $secret,
        );

        return [
            'secret'      => $secret,
            'otpauth_uri' => $uri,
            'qr_svg'      => $this->qrSvg($uri),
        ];
    }

    /**
     * Finish enrolment with a code from the authenticator.
     *
     * @return array<int,string> plaintext recovery codes — shown exactly once
     *
     * @throws MfaFailure
     */
    public function confirmEnrolment(User $user, string $code): array
    {
        if (! $user->mfa_secret) {
            throw new MfaFailure('mfa_not_started', 'Start two-factor setup before confirming it.');
        }

        if (! $this->verifyTotp($user, $code)) {
            throw new MfaFailure('invalid_code', 'That code is not valid. Check your authenticator and try again.');
        }

        $codes = $this->generateRecoveryCodes();

        $user->mfa_confirmed_at = now();
        $user->mfa_recovery_codes = array_map(fn (string $c) => Hash::make($c), $codes);
        $user->save();

        return $codes;
    }

    /** Turn MFA off entirely and discard the secret and recovery codes. */
    public function disable(User $user): void
    {
        $user->mfa_secret = null;
        $user->mfa_confirmed_at = null;
        $user->mfa_recovery_codes = null;
        $user->save();
    }

    /**
     * Issue a fresh set of recovery codes, invalidating the previous set.
     *
     * @return array<int,string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->mfa_recovery_codes = array_map(fn (string $c) => Hash::make($c), $codes);
        $user->save();

        return $codes;
    }

    /**
     * Accept either a TOTP code or an unused recovery code. A recovery code
     * is consumed on use.
     */
    public function verify(User $user, string $code): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        return $this->verifyTotp($user, $code) || $this->consumeRecoveryCode($user, $code);
    }

    public function unusedRecoveryCodeCount(User $user): int
    {
        return count($user->mfa_recovery_codes ?? []);
    }

    private function verifyTotp(User $user, string $code): bool
    {
        if (! $user->mfa_secret) {
            return false;
        }

        // Digits only — a recovery code must not be fed to the TOTP verifier.
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        $timestamp = $this->google2fa->verifyKeyNewer(
            (string) $user->mfa_secret,
            $code,
            $this->lastUsedTimestamp($user),
            self::WINDOW,
        );

        if ($timestamp === false) {
            return false;
        }

        // verifyKeyNewer refuses anything at or before the stored timestamp,
        // which is what stops a code being replayed inside its own window.
        $this->rememberUsedTimestamp($user, is_int($timestamp) ? $timestamp : time());

        return true;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->mfa_recovery_codes ?? [];

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);
                $user->mfa_recovery_codes = array_values($hashes);
                $user->save();

                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::lower(Str::random(5) . '-' . Str::random(5)))
            ->all();
    }

    private function lastUsedTimestamp(User $user): ?int
    {
        return Cache::get($this->replayKey($user));
    }

    private function rememberUsedTimestamp(User $user, int $timestamp): void
    {
        // Only needs to outlive the acceptance window.
        Cache::put($this->replayKey($user), $timestamp, now()->addMinutes(5));
    }

    private function replayKey(User $user): string
    {
        return "mfa_last_totp:{$user->id}";
    }

    private function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd()));

        return $writer->writeString($uri);
    }
}
