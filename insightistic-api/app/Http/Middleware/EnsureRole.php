<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route by the user's role in the CURRENT organization.
 * Usage: ->middleware('role:owner') or ->middleware('role:owner,admin').
 * Runs after the 'tenant' middleware (which resolves the organization).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $org  = app(Tenancy::class)->organization();

        if (! $user || ! $org) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $role = $user->roleIn($org);

        if (! $role || ! in_array($role, $roles, true)) {
            return response()->json([
                'message' => 'Your role does not permit this action.',
                'code'    => 'insufficient_role',
            ], 403);
        }

        return $next($request);
    }
}
