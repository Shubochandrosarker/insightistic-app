<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| In the recommended "proxy mode" the browser only ever calls the app's own
| origin, so CORS is irrelevant. This config exists so that "direct mode"
| (browser -> https://api.insightistic.com) also works without the browser
| reporting "Failed to fetch" on the pre-flight request.
|
| Auth uses Bearer tokens (not cookies), so credentials are not required and
| a wildcard origin is safe. Lock it down by setting CORS_ALLOWED_ORIGINS to a
| comma-separated list, e.g. "https://app.insightistic.com,https://insightistic.com".
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins ?: ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,
];
