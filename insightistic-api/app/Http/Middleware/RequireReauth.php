<?php

namespace App\Http\Middleware;

use App\Services\Auth\ReauthGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an action behind a recent password (+ MFA) confirmation.
 * Usage: ->middleware('reauth')
 *
 * Applied to the actions where a stolen-but-still-valid session is most
 * damaging: billing changes, license regeneration, super-admin promotion,
 * organization deletion, and starting an impersonation session.
 *
 * Responds 428 Precondition Required rather than 403 so the SPA can tell
 * "you may do this, just confirm first" apart from "your role forbids this"
 * and show a confirm dialog instead of an error.
 */
class RequireReauth
{
    public function __construct(private readonly ReauthGuard $guard)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->guard->confirmedRecently($request)) {
            return response()->json([
                'message'    => 'Confirm your password to continue.',
                'code'       => 'reauth_required',
                'expires_in' => ReauthGuard::TTL_MINUTES * 60,
            ], 428);
        }

        return $next($request);
    }
}
