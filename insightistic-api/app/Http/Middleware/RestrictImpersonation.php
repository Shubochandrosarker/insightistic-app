<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces spec §13.9's impersonation constraints on every authenticated
 * customer-app request, based on the current Sanctum token's abilities
 * (see Admin\ImpersonationController::start()). A normal user's token has
 * the Sanctum default `['*']` and never carries `impersonated`, so this is
 * a no-op for every real user session.
 *
 * - `impersonated` present, `impersonated-elevated` absent: read-only —
 *   any non-GET/HEAD/OPTIONS request is refused.
 * - Billing is blocked outright regardless of elevation — "no access to
 *   secrets or payment methods" applies even to an elevated session.
 */
class RestrictImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $abilities = $token?->abilities ?? [];

        if (! in_array('impersonated', $abilities, true)) {
            return $next($request);
        }

        if ($request->is('api/billing/*')) {
            return response()->json([
                'message' => 'Billing actions are not available during an impersonation session.',
                'code'    => 'impersonation_restricted',
            ], 403);
        }

        $elevated = in_array('impersonated-elevated', $abilities, true);
        if (! $elevated && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json([
                'message' => 'This impersonation session is read-only.',
                'code'    => 'impersonation_read_only',
            ], 403);
        }

        return $next($request);
    }
}
