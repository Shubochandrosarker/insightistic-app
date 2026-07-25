<?php

return [
    'ai' => [
        // 'openai' uses the OpenAI-compatible API; 'wpistic_gateway' calls the
        // shared WordPressistic AI Brain gateway instead; 'rule' is a $0
        // deterministic summarizer (no key, no spend) — great for testing and
        // as a quota fallback. Falls back to 'rule' if the chosen provider
        // isn't fully configured (see AppServiceProvider::register()).
        'provider' => env('INSIGHTISTIC_AI_PROVIDER', 'openai'),
        'model'    => env('INSIGHTISTIC_AI_MODEL', 'gpt-4o-mini'),
        'api_key'  => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout'  => (int) env('INSIGHTISTIC_AI_TIMEOUT', 60),
    ],

    // Shared WordPressistic AI Brain gateway (wpistic-ai-gateway Worker).
    // Select it with INSIGHTISTIC_AI_PROVIDER=wpistic_gateway above.
    'wpistic_gateway' => [
        'base_url'      => env('WPISTIC_GATEWAY_URL', 'https://wpistic-ai-gateway.wordpressistic.workers.dev'),
        'shared_secret' => env('WPISTIC_GATEWAY_SHARED_SECRET'),
        'agent'         => env('WPISTIC_GATEWAY_AGENT', 'analytics-pro'),
        // Tier asserted to the gateway for rate limits (free|starter|pro|agency).
        // Insightistic's own license/feature gate already decides whether an
        // org may use AI insights at all before this is ever called.
        'tier'          => env('WPISTIC_GATEWAY_TIER', 'agency'),
        'timeout'       => (int) env('WPISTIC_GATEWAY_TIMEOUT', 30),
    ],

    'reports' => [
        // Filesystem disk for generated HTML/PDF (run `php artisan storage:link`).
        'disk' => env('INSIGHTISTIC_REPORTS_DISK', 'public'),
    ],

    // ---- AI Knowledge Base (Phase 4) --------------------------------------
    'embeddings' => [
        // 'openai' = any OpenAI-compatible /embeddings endpoint (OpenAI, the
        // WordPressistic gateway passthrough, etc). 'none' = no embeddings:
        // ingestion still indexes content and retrieval runs on PostgreSQL
        // full-text search only — honest degradation, never fake vectors.
        'provider'  => env('INSIGHTISTIC_EMBEDDINGS_PROVIDER', 'none'),
        'model'     => env('INSIGHTISTIC_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        // Canonical supported dimension — must match the vector(1536) column.
        'dimension' => (int) env('INSIGHTISTIC_EMBEDDINGS_DIMENSION', 1536),
        'api_key'   => env('INSIGHTISTIC_EMBEDDINGS_API_KEY', env('OPENAI_API_KEY')),
        'base_url'  => env('INSIGHTISTIC_EMBEDDINGS_BASE_URL', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')),
        'timeout'   => (int) env('INSIGHTISTIC_EMBEDDINGS_TIMEOUT', 30),
    ],

    'knowledge' => [
        // Disk for uploaded source files ('local' default; set an s3 disk in
        // production — see config/filesystems.php and docs/DEPLOYMENT.md).
        'disk'            => env('INSIGHTISTIC_KNOWLEDGE_DISK', 'local'),
        'max_file_mb'     => (int) env('INSIGHTISTIC_KNOWLEDGE_MAX_FILE_MB', 20),
        // Hard cap on extracted text per source (defense against zip-bomb-ish
        // documents; ~4 chars/token puts 2 MB ≈ 500k tokens).
        'max_content_bytes' => (int) env('INSIGHTISTIC_KNOWLEDGE_MAX_CONTENT_BYTES', 2_000_000),
        'crawl_timeout'   => (int) env('INSIGHTISTIC_KNOWLEDGE_CRAWL_TIMEOUT', 20),
        'crawl_max_bytes' => (int) env('INSIGHTISTIC_KNOWLEDGE_CRAWL_MAX_BYTES', 10_485_760),
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

    // NOTE: the old `super_admin_email` / SUPER_ADMIN_EMAIL auto-promotion
    // was removed as a security fix. Super admins are created only via the
    // insightistic:super-admin:* artisan commands or promotion by an existing
    // super admin. See docs/SUPER-ADMIN.md.
];
