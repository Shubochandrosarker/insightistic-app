<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates platform-owner ("super admin") routes. Runs after auth:sanctum and is
 * deliberately NOT combined with the `tenant` middleware, so admin queries see
 * every organization rather than a single tenant.
 */
class SuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Super admin access required.',
                'code'    => 'not_super_admin',
            ], 403);
        }

        return $next($request);
    }
}
