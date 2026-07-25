<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "Confirm it's really you" before a sensitive action (spec Phase 1.4).
 *
 * The confirmation is bound to the **specific access token** that performed
 * it, not to the user. Binding to the user would mean confirming on your
 * laptop also unlocks billing on a token someone else stole — which defeats
 * the point of asking again.
 *
 * Marks live in the cache, not the database: they are short-lived by design
 * and should evaporate on their own rather than needing cleanup.
 */
class ReauthGuard
{
    /** How long a confirmation stays good for, in minutes. */
    public const TTL_MINUTES = 10;

    public function confirm(Request $request): void
    {
        $key = $this->key($request);

        if ($key !== null) {
            Cache::put($key, true, now()->addMinutes(self::TTL_MINUTES));
        }
    }

    public function confirmedRecently(Request $request): bool
    {
        $key = $this->key($request);

        return $key !== null && Cache::get($key) === true;
    }

    /** Drop the mark — used on logout and when MFA settings change. */
    public function forget(Request $request): void
    {
        $key = $this->key($request);

        if ($key !== null) {
            Cache::forget($key);
        }
    }

    private function key(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();

        // No token id means a non-Sanctum context (e.g. a unit test acting as
        // a user). Returning null makes confirmedRecently() false, so the
        // guard fails closed rather than silently letting the action through.
        return isset($token->id) ? "reauth:{$token->id}" : null;
    }
}
