<?php

return [
    'release' => [
        'sha'      => env('INSIGHTISTIC_RELEASE', 'unknown'),
        'built_at' => env('INSIGHTISTIC_BUILD_TIME'),
    ],

    'marketing' => [
        'google_ads' => [
            'enabled'              => (bool) env('GOOGLE_ADS_CONVERSIONS_ENABLED', false),
            'endpoint'             => env('GOOGLE_ADS_CONVERSIONS_ENDPOINT', 'https://googleads.googleapis.com'),
            'developer_token'     => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
            'access_token'        => env('GOOGLE_ADS_ACCESS_TOKEN'),
            'customer_id'         => env('GOOGLE_ADS_CUSTOMER_ID'),
            'conversion_action_id' => env('GOOGLE_ADS_CONVERSION_ACTION_ID'),
            'login_customer_id'   => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        ],
        'meta' => [
            'enabled'      => (bool) env('META_CONVERSIONS_ENABLED', false),
            'endpoint'     => env('META_CONVERSIONS_ENDPOINT', 'https://graph.facebook.com/v20.0'),
            'pixel_id'     => env('META_PIXEL_ID'),
            'access_token' => env('META_CONVERSIONS_ACCESS_TOKEN'),
        ],
    ],

    'ai' => [
        // Retained for older integrations. Dashboard AI uses the plan-aware
        // provider chains configured below; this value no longer selects it.
        'provider' => env('INSIGHTISTIC_AI_PROVIDER', 'plan_aware'),
        'model'    => env('INSIGHTISTIC_AI_MODEL', 'gpt-4o-mini'),
        'api_key'  => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        // Keep AI calls below the 300s proxy/FastCGI ceiling while allowing
        // slower generation responses to finish instead of dying at 60s.
        'timeout'  => (int) env('INSIGHTISTIC_AI_TIMEOUT', 240),
    ],

    // Plan-aware OpenRouter routing. The free router chooses from compatible
    // zero-cost models; paid subscribers use GPT-6 Luna with GLM-5.3 Flash as
    // the ordered fallback. Keys remain server-side and are never exposed to
    // the Next.js app.
    'openrouter' => [
        'api_key'              => env('OPENROUTER_API_KEY'),
        'base_url'             => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'timeout'              => (int) env('OPENROUTER_TIMEOUT', 180),
        'free_model'           => env('OPENROUTER_FREE_MODEL', 'openrouter/free'),
        'paid_model'           => env('OPENROUTER_PAID_MODEL', 'openai/gpt-6-luna'),
        'paid_fallback_models' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OPENROUTER_PAID_FALLBACK_MODELS', 'z-ai/glm-5.3-flash')),
        ))),
    ],

    // Shared WordPressistic AI Brain gateway (wpistic-ai-gateway Worker).
    'wpistic_gateway' => [
        'base_url'      => env('WPISTIC_GATEWAY_URL', 'https://ai.wpistic.com'),
        'product_key'   => env('WPISTIC_GATEWAY_PRODUCT_KEY'),
        'product_id'    => env('WPISTIC_GATEWAY_PRODUCT_ID', 'insightistic'),
        'model'         => env('WPISTIC_GATEWAY_MODEL', 'glm-5.3'),
        'max_tokens'    => (int) env('WPISTIC_GATEWAY_MAX_TOKENS', 1200),
        // The product key identifies Insightistic; X-Wpistic-User scopes
        // gateway daily usage to each customer organization.
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

    /*
     * Direct SaaS integrations (spec Phase 3.2). These credentials belong to
     * the SaaS, never to a WordPress site — refresh tokens are exchanged and
     * stored server-side only, and are never sent to the browser or written
     * into the WordPress options table.
     *
     * The Google client is the same OAuth app used for social login, but with
     * its own redirect URI and read-only analytics scopes, so consent for
     * "sign in with Google" never silently grants analytics access.
     */
    'integrations' => [
        'google' => [
            'client_id'     => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect'      => env(
                'INTEGRATIONS_GOOGLE_REDIRECT_URI',
                rtrim(env('APP_URL', 'http://localhost'), '/') . '/api/integrations/google/callback',
            ),
            'scopes' => [
                'ga4' => ['https://www.googleapis.com/auth/analytics.readonly'],
                'gsc' => ['https://www.googleapis.com/auth/webmasters.readonly'],
            ],
        ],

        // Cloudflare uses a scoped API token (Analytics:Read), not OAuth.
        'cloudflare' => [
            'api_base' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),
        ],

        'timeout' => (int) env('INTEGRATIONS_HTTP_TIMEOUT', 20),

        // How stale a source may get before the dashboard calls it degraded.
        'stale_after_hours' => (int) env('INTEGRATIONS_STALE_AFTER_HOURS', 48),
    ],

    /*
     * Email analytics (spec Phase 3.4). Mailchimp and Klaviyo are pulled by
     * the SaaS using a per-site API key entered in the dashboard, so there is
     * no server-level credential to configure here. FluentCRM, AutomateWoo
     * and WooCommerce mail are pushed by the WordPress connector instead,
     * because their data is not reachable from outside the site.
     */
    'email' => [
        'timeout'        => (int) env('EMAIL_ANALYTICS_TIMEOUT', 20),
        'campaign_limit' => (int) env('EMAIL_ANALYTICS_CAMPAIGN_LIMIT', 50),
    ],

    /*
     * Privacy and compliance (spec Phase 10).
     *
     * Organization deletion is two-stage: a request schedules the purge
     * `deletion_grace_days` out and can be cancelled until then. Immediate
     * hard deletion has no undo, and a mis-click is far more common than a
     * genuine need to erase a tenant within the hour.
     */
    /*
     * PageSpeed Insights, run from the SaaS backend (spec Phase 3.3).
     *
     * The API key is optional — Google allows keyless requests at a much
     * lower quota, which is fine for a small install and useless for a busy
     * one. Both caps below exist because a nightly sweep across every site
     * would otherwise exhaust the allowance in minutes.
     */
    'pagespeed' => [
        'api_key' => env('PAGESPEED_API_KEY'),
        'timeout' => (int) env('PAGESPEED_TIMEOUT', 90),
        // Global ceiling across all sites, per minute.
        'max_per_minute' => (int) env('PAGESPEED_MAX_PER_MINUTE', 20),
        // Per-site ceiling per day, so one site cannot starve the rest.
        // 0 disables the cap.
        'max_per_site_per_day' => (int) env('PAGESPEED_MAX_PER_SITE_PER_DAY', 8),
        // Regression alerting is not configured here: AlertDetector already
        // owns that rule (PERFORMANCE_REGRESSION_POINTS) and consumes these
        // runs automatically. A second threshold would just be dead config.
    ],

    'privacy' => [
        'deletion_grace_days' => (int) env('PRIVACY_DELETION_GRACE_DAYS', 7),
        'export_disk'         => env('PRIVACY_EXPORT_DISK', 'local'),
        // How long a generated archive stays downloadable. A full tenant
        // dump sitting on disk indefinitely is a liability.
        'export_ttl_days'     => (int) env('PRIVACY_EXPORT_TTL_DAYS', 7),
    ],

    /*
     * Safe WooCommerce Query Engine (spec Phase 2 / P1). Budgets tune the
     * blast radius of any single semantic-plan query — see
     * docs/SAFE-QUERY-ENGINE.md for the full pipeline. Numbers here match
     * docs/04-LOCAL-QUERY-SECURITY.md's "Initial query budgets"; tune from
     * telemetry once this ships.
     */
    'query_engine' => [
        // Postgres statement_timeout for a single compiled query, in ms.
        'timeout_ms' => (int) env('QUERY_ENGINE_TIMEOUT_MS', 8000),
        // Row cap for a plan with no explicit dimension breakdown.
        'max_rows' => (int) env('QUERY_ENGINE_MAX_ROWS', 2000),
        // Row cap when the plan requests a dimension breakdown for a chart
        // (e.g. top products) — charts render far fewer points than a table.
        'chart_max_rows' => (int) env('QUERY_ENGINE_CHART_MAX_ROWS', 500),
        // Max concurrently in-flight interactive queries per organization.
        // Exceeding this returns a structured 429, not a queued/blocked request.
        'max_concurrent_per_org' => (int) env('QUERY_ENGINE_MAX_CONCURRENT_PER_ORG', 3),
        // How long a concurrency slot is held before being force-released
        // (crash safety net — a normal query releases its slot itself).
        'concurrency_lock_ttl_seconds' => (int) env('QUERY_ENGINE_CONCURRENCY_LOCK_TTL', 30),
    ],

    // NOTE: the old `super_admin_email` / SUPER_ADMIN_EMAIL auto-promotion
    // was removed as a security fix. Super admins are created only via the
    // insightistic:super-admin:* artisan commands or promotion by an existing
    // super admin. See docs/SUPER-ADMIN.md.
];
