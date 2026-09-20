<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Outermost, so the timing covers everything the application does —
         * including the middleware below it. Measuring from further in hides
         * exactly the layers most likely to be slow: tenancy resolution,
         * rate limiting, auth.
         */
        $middleware->prepend(\App\Http\Middleware\RequestTelemetry::class);

        // Baseline security headers on every response (API serves JSON only).
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Laravel's default builder registers redirectGuestsTo(route('login')).
        // This API has no login route — an unauthenticated non-JSON request
        // (e.g. a browser POST without an Accept header) would throw
        // RouteNotFoundException INSIDE the auth middleware and 500 before
        // the exception handler ever sees it. Null → a clean
        // AuthenticationException, which the renderer below turns into 401
        // JSON for api/* paths.
        $middleware->redirectGuestsTo(fn () => null);

        // Insightistic route-middleware aliases.
        $middleware->alias([
            'tenant'                 => \App\Http\Middleware\ResolveOrganization::class,
            'connector.auth'         => \App\Http\Middleware\ConnectorAuth::class,
            'site.access'            => \App\Http\Middleware\EnsureSiteAccess::class,
            'role'                   => \App\Http\Middleware\EnsureRole::class,
            'super_admin'            => \App\Http\Middleware\SuperAdmin::class,
            'restrict.impersonation' => \App\Http\Middleware\RestrictImpersonation::class,
            'reauth'                 => \App\Http\Middleware\RequireReauth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Error tracking (spec Phase 2.3). Inert unless SENTRY_LARAVEL_DSN is
         * set, and everything that does go out passes through EventScrubber
         * first — this API's exception payloads can carry OAuth refresh
         * tokens, license keys and customer records.
         */
        \Sentry\Laravel\Integration::handles($exceptions);

        // The API has no `login` route, so Laravel's default unauthenticated
        // renderer (redirect()->guest(route('login'))) crashes with a 500 for
        // any non-JSON client hitting a protected route — e.g. a browser POST
        // without an Accept header. API routes always answer 401 JSON.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Never let a database error leak schema/table/SQL details to an API
        // client — regardless of APP_DEBUG, which can be misconfigured on any
        // given environment. Laravel's default debug-mode renderer would
        // otherwise return the raw SQLSTATE message (including file paths and
        // the full query) straight to the browser.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                report($e);

                return response()->json([
                    'message' => 'Something went wrong. Please try again.',
                ], 500);
            }
        });
    })->create();
