<?php

use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Laravel Sanctum configuration
|--------------------------------------------------------------------------
|
| The frontend authenticates with Bearer personal-access-tokens (not the SPA
| cookie flow), so stateful domains are mostly irrelevant here, but we keep the
| standard defaults so token issuance/validation behaves as documented.
|
*/

return [
    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    'guard' => ['web'],

    // Short-lived sessions (security hardening): tokens expire after this
    // many minutes and the SPA re-authenticates. Set 0/empty to disable
    // (not recommended). Existing long-lived tokens issued before this
    // change expire `expiration` minutes after their creation date.
    'expiration' => ((int) env('SANCTUM_EXPIRATION_MINUTES', 720)) ?: null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
