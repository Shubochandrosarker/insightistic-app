<?php

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
|
| Active billing provider for NEW checkouts. Existing Stripe subscriptions
| keep working regardless of this value — they continue to project into the
| `subscriptions` table via the Stripe integration until they are migrated.
| Paddle secrets are server-side only and must never reach the frontend.
|
*/

return [
    // Active billing provider for NEW checkouts. Existing Stripe
    // subscriptions keep working regardless of this value.
    'provider' => env('BILLING_PROVIDER', 'stripe'),

    'paddle' => [
        'sandbox' => env('PADDLE_ENV', 'sandbox') === 'sandbox',
        'api_key' => env('PADDLE_API_KEY'),
        'client_token' => env('PADDLE_CLIENT_TOKEN'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        'default_currency' => env('PADDLE_DEFAULT_CURRENCY', 'USD'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
