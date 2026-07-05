<?php

return [
    'ai' => [
        // 'openai' uses the OpenAI-compatible API; 'rule' is a $0 deterministic
        // summarizer (no key, no spend) — great for testing and as a quota fallback.
        'provider' => env('INSIGHTISTIC_AI_PROVIDER', 'openai'),
        'model'    => env('INSIGHTISTIC_AI_MODEL', 'gpt-4o-mini'),
        'api_key'  => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout'  => (int) env('INSIGHTISTIC_AI_TIMEOUT', 60),
    ],

    'reports' => [
        // Filesystem disk for generated HTML/PDF (run `php artisan storage:link`).
        'disk' => env('INSIGHTISTIC_REPORTS_DISK', 'public'),
    ],

    // Frontend app base (Stripe redirects, invite links).
    'app_url' => env('INSIGHTISTIC_APP_URL', 'https://app.insightistic.com'),

    // Trial length for new organizations (spec: 7-day free trial).
    'trial_days' => (int) env('INSIGHTISTIC_TRIAL_DAYS', 7),

    'license' => [
        // Seconds between plugin-side validation calls (daily WP-Cron).
        'validate_interval' => (int) env('INSIGHTISTIC_LICENSE_VALIDATE_INTERVAL', 86400),
        // Hours a plugin may keep using cached entitlements when the API is
        // unreachable before it soft-locks advanced modules.
        'grace_hours' => (int) env('INSIGHTISTIC_LICENSE_GRACE_HOURS', 72),
        // Days of full access kept after a failed payment before suspension.
        'payment_grace_days' => (int) env('INSIGHTISTIC_PAYMENT_GRACE_DAYS', 7),
    ],

    'stripe' => [
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
