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
        // Customer Portal configuration (bpc_…). Null = Stripe's default config.
        'portal_configuration' => env('STRIPE_PORTAL_CONFIGURATION'),
        // Safety latch: live keys (sk_live_/rk_live_) are refused unless this
        // is explicitly true. Flip only after Shuvo signs off on go-live.
        'allow_live' => (bool) env('STRIPE_ALLOW_LIVE', false),
        // A webhook received within this many hours counts as "recent" on
        // Admin → System Health.
        'webhook_recent_hours' => (int) env('STRIPE_WEBHOOK_RECENT_HOURS', 72),
    ],

    // Providers offered at checkout (customer chooses). Stripe is the
    // default first option; Paddle remains available as the explicit
    // secondary provider. Credit packs stay Paddle-only for now.
    'checkout_providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('BILLING_CHECKOUT_PROVIDERS', 'stripe,paddle'))))),
];
