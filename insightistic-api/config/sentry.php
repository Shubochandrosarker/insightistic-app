<?php

use App\Services\Observability\EventScrubber;

/*
|--------------------------------------------------------------------------
| Sentry
|--------------------------------------------------------------------------
| Error tracking, disabled entirely when SENTRY_LARAVEL_DSN is empty — which
| is the default, so a fresh install sends nothing anywhere until someone
| deliberately opts in.
|
| The scrubbing in `before_send` is not optional decoration. Insightistic
| handles OAuth refresh tokens, license keys, connector HMAC secrets and
| customer records, and an exception payload can carry any of them in its
| request body, headers or local variables. Anything shipped to a third party
| has left the building, so the scrubber runs on every event and errs towards
| dropping too much.
*/

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'release' => env('SENTRY_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
     * Never send request bodies. Laravel would otherwise attach the whole
     * payload, which for this API includes login credentials, TOTP codes,
     * Cloudflare API tokens and Google authorization codes.
     */
    'send_default_pii' => false,

    'max_request_body_size' => 'none',

    /*
     * Traces are sampled, not captured wholesale — SENTRY_TRACES_SAMPLE_RATE=0
     * (the default) means performance data costs nothing until someone wants
     * it. OpenTelemetry (config/otel.php) is the primary tracing path; this is
     * here so Sentry users get spans attached to their errors.
     */
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),

    /*
     * Breadcrumbs record what happened before the error. SQL *bindings* are
     * excluded because they are the actual values — email addresses, tokens,
     * order totals — while the statement shape alone is enough to debug.
     */
    'breadcrumbs' => [
        'logs'          => true,
        'cache'         => false,
        'livewire'      => false,
        'sql_queries'   => true,
        'sql_bindings'  => false,
        'queue_info'    => true,
        'command_info'  => true,
        'http_client_requests' => true,
        'notifications' => false,
    ],

    'tracing' => [
        'queue_job_transactions' => (bool) env('SENTRY_TRACE_QUEUE_ENABLED', false),
        'sql_bindings'  => false,
        'redis_commands' => false,
        'default_integrations' => true,
    ],

    /*
     * Last line of defence before anything leaves the process. Returning null
     * drops the event entirely.
     */
    'before_send' => [EventScrubber::class, 'scrub'],

    'before_send_transaction' => [EventScrubber::class, 'scrubTransaction'],

    'before_breadcrumb' => [EventScrubber::class, 'scrubBreadcrumb'],

    'ignore_exceptions' => [
        // Expected control flow, not defects: a 404 on a cross-tenant id is
        // the tenancy model working, and a 422 is a user typo.
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
    ],
];
