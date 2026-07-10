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
        // Insightistic route-middleware aliases.
        $middleware->alias([
            'tenant'         => \App\Http\Middleware\ResolveOrganization::class,
            'connector.auth' => \App\Http\Middleware\ConnectorAuth::class,
            'site.access'    => \App\Http\Middleware\EnsureSiteAccess::class,
            'role'           => \App\Http\Middleware\EnsureRole::class,
            'super_admin'    => \App\Http\Middleware\SuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
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
