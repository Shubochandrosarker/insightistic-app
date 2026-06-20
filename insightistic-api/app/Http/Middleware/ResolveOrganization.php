<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active organization for an authenticated user and loads it into
 * the Tenancy context. After this runs, every BelongsToOrganization model is
 * automatically filtered to this org.
 *
 * Org selection order:
 *   1. X-Organization-Id header (must be one the user belongs to)
 *   2. The user's first organization
 */
class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $requestedId = $request->header('X-Organization-Id');

        $organization = $requestedId
            ? $user->organizations()->where('organizations.id', $requestedId)->first()
            : $user->organizations()->first();

        if (! $organization) {
            return response()->json(['message' => 'No accessible organization.'], 403);
        }

        app(Tenancy::class)->setOrganization($organization);

        return $next($request);
    }
}
