<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For routes with a {site} parameter. Because Site is tenant-scoped, a site
 * from another organization simply won't be found (404, not 403 — we don't
 * confirm its existence to outsiders).
 *
 * Extra rule: a client_viewer may only touch sites explicitly granted to them
 * via site_user_access.
 */
class EnsureSiteAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $siteId = $request->route('site');
        $site = Site::find($siteId); // global org scope already applied

        if (! $site) {
            return response()->json(['message' => 'Site not found.'], 404);
        }

        $user = $request->user();
        $role = $user->roleIn($site->organization);

        if ($role === 'client_viewer') {
            $granted = $site->newQuery()
                ->whereExists(function ($q) use ($site, $user) {
                    $q->from('site_user_access')
                      ->whereColumn('site_user_access.site_id', 'sites.id')
                      ->where('site_user_access.user_id', $user->id);
                })
                ->whereKey($site->id)
                ->exists();

            if (! $granted) {
                return response()->json(['message' => 'Site not found.'], 404);
            }
        }

        app(Tenancy::class)->setSite($site);
        $request->attributes->set('site', $site);

        return $next($request);
    }
}
