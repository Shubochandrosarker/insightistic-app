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

    'stripe' => [
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
