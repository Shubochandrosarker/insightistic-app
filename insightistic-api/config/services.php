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

$redirectBase = rtrim((string) env(
    'OAUTH_REDIRECT_BASE',
    env('INSIGHTISTIC_APP_URL', 'https://app.insightistic.com')
), '/');

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
        'redirect' => env('GOOGLE_REDIRECT_URI', $redirectBase . '/api/auth/oauth/google/callback'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI', $redirectBase . '/api/auth/oauth/github/callback'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', $redirectBase . '/api/auth/oauth/microsoft/callback'),
        // 'common' allows both work/school and personal Microsoft accounts.
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],
];
