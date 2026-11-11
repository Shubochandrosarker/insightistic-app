<?php

/*
|--------------------------------------------------------------------------
| Third-Party Services
|--------------------------------------------------------------------------
|
| OAuth (social login) credentials for Google, Microsoft and GitHub. A provider
| is considered "enabled" only when both its client id and secret are present,
| so the frontend hides buttons that aren't configured.
|
| Redirect URIs default to the APP domain so social login works in proxy mode
| (browser only ever talks to the app origin). Override per-provider, or set
| OAUTH_REDIRECT_BASE to point callbacks at the API domain in direct mode.
|
*/

// `?:` (not env()'s default) so an EMPTY value — what docker compose's
// `${VAR:-}` passes through — falls back instead of yielding a blank or
// relative redirect_uri.
$redirectBase = rtrim((string) (env('OAUTH_REDIRECT_BASE')
    ?: env('INSIGHTISTIC_APP_URL')
    ?: 'https://app.insightistic.com'), '/');

return [
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Google only accepts redirect URIs registered on the OAuth client, and the
        // integrations callback is the one registered for it. Social login reuses
        // it; IntegrationsController::googleCallback hands login flows back to
        // OAuthController (it recognises the login state nonce).
        'redirect' => env('GOOGLE_REDIRECT_URI')
            ?: env('INTEGRATIONS_GOOGLE_REDIRECT_URI')
            ?: $redirectBase . '/api/auth/oauth/google/callback',
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI') ?: $redirectBase . '/api/auth/oauth/github/callback',
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI') ?: $redirectBase . '/api/auth/oauth/microsoft/callback',
        // 'common' allows both work/school and personal Microsoft accounts.
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

    // DataForSEO SERP API (rank tracking, Phase 10) — HTTP Basic auth.
    // Rank checks are skipped entirely while either value is empty, so an
    // install without credentials costs nothing.
    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        // Results per check. DataForSEO bills per 10 results (rounded up), so
        // 100 = top-100 = 10 SERPs per keyword per check. Max 200.
        'depth' => (int) env('DATAFORSEO_DEPTH', 100),
        // Parallel live requests (one keyword each).
        'concurrency' => (int) env('DATAFORSEO_CONCURRENCY', 5),
        // Seconds per live request (deep SERPs take several seconds).
        'timeout' => (int) env('DATAFORSEO_TIMEOUT', 60),
    ],
];
