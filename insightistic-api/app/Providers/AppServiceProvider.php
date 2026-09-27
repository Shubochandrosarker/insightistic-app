<?php

namespace App\Providers;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTierResolver;
use App\Services\Ai\OpenRouterProvider;
use App\Services\Ai\PlanAwareAiProvider;
use App\Services\Ai\RuleProvider;
use App\Services\Ai\WpisticGatewayProvider;
use App\Support\Tenancy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One Tenancy context per request.
        $this->app->scoped(Tenancy::class, fn () => new Tenancy());

        // Observability (spec Phase 2.3). Singletons because Tracing holds the
        // exporter's batch processor — one per process, not one per resolve,
        // or spans end up split across providers that each flush separately.
        $this->app->singleton(\App\Services\Observability\Tracing::class);
        $this->app->singleton(\App\Services\Observability\Telemetry::class);
        $this->app->singleton(\App\Services\Observability\QueueHealth::class);

        // One plan-aware routing policy shared by insights, reports, Ask,
        // AI visibility, and investigations. Every external attempt is
        // independently metered so fallback behavior is visible in ops.
        $this->app->bind(AiProvider::class, function () {
            $gatewayConfig = config('insightistic.wpistic_gateway');
            $routerConfig = config('insightistic.openrouter');
            $telemetry = $this->app->make(\App\Services\Observability\Telemetry::class);
            $tracing = $this->app->make(\App\Services\Observability\Tracing::class);
            $meter = fn (AiProvider $provider, string $name) => new \App\Services\Observability\MeteredAiProvider(
                $provider,
                $telemetry,
                $tracing,
                $name,
            );

            $gatewayConfigured = ! empty($gatewayConfig['product_key']);
            $routerConfigured = ! empty($routerConfig['api_key']);
            $gateway = $gatewayConfigured
                ? fn () => $meter(new WpisticGatewayProvider($this->app->make(\App\Services\Ai\WpisticGatewayClient::class)), 'wpistic_gateway')
                : null;

            $free = [];
            $paid = [];

            if ($gateway) {
                $free[] = $gateway();
            }

            if ($routerConfigured) {
                $free[] = $meter(new OpenRouterProvider($routerConfig + [
                    'model' => $routerConfig['free_model'],
                    'fallback_models' => [],
                ]), 'openrouter');
                $paid[] = $meter(new OpenRouterProvider($routerConfig + [
                    'model' => $routerConfig['paid_model'],
                    'fallback_models' => $routerConfig['paid_fallback_models'],
                ]), 'openrouter');
            }

            if ($gateway) {
                $paid[] = $gateway();
            }

            $rule = $meter(new RuleProvider(), 'rule');
            $free[] = $rule;
            $paid[] = $rule;

            return new PlanAwareAiProvider($free, $paid, $this->app->make(AiTierResolver::class));
        });

        // Embedding provider for the knowledge base. 'none' (or a missing
        // key) binds the null provider: retrieval degrades to full-text
        // search — real results, no fabricated vectors.
        $this->app->bind(\App\Services\Knowledge\EmbeddingProvider::class, function () {
            $cfg = config('insightistic.embeddings');

            return ($cfg['provider'] ?? 'none') === 'openai' && ! empty($cfg['api_key'])
                ? new \App\Services\Knowledge\OpenAiEmbeddingProvider($cfg)
                : new \App\Services\Knowledge\NullEmbeddingProvider();
        });

        // SSRF-guarded fetcher with configured crawl limits.
        $this->app->bind(\App\Services\Knowledge\SafeUrlFetcher::class, function () {
            return new \App\Services\Knowledge\SafeUrlFetcher(
                (int) config('insightistic.knowledge.crawl_timeout', 20),
                (int) config('insightistic.knowledge.crawl_max_bytes', 10_485_760),
            );
        });

        // Semantic Metric Registry (spec Phase 1): one versioned catalog of
        // canonical metric definitions. Singleton because the catalog is
        // static per-process — no request-scoped state, no DB round trip.
        $this->app->singleton(\App\Metrics\MetricRegistry::class);

        // Safe WooCommerce Query Engine (spec Phase 2 / P1).
        $this->app->bind(\App\Query\QueryConcurrencyLimiter::class, function () {
            return new \App\Query\QueryConcurrencyLimiter(
                (int) config('insightistic.query_engine.max_concurrent_per_org', 3),
                (int) config('insightistic.query_engine.concurrency_lock_ttl_seconds', 30),
            );
        });
    }

    public function boot(): void
    {
        // Named rate limiters (attached in routes/api.php as throttle:<name>).
        // auth: brute-force protection on credential endpoints.
        \Illuminate\Support\Facades\RateLimiter::for('auth', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->ip());
        });
        // license: plugin activation/validation — per IP and per key prefix so
        // a single attacker can't enumerate keys from many source IPs cheaply.
        \Illuminate\Support\Facades\RateLimiter::for('license', function (\Illuminate\Http\Request $request) {
            $key = substr((string) $request->input('license_key', ''), 0, 24);
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('lic-ip:' . $request->ip()),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('lic-key:' . sha1($key)),
            ];
        });
        // connector: HMAC sync traffic, per key id.
        \Illuminate\Support\Facades\RateLimiter::for('connector', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(120)
                ->by('conn:' . ($request->header('X-INS-Key-Id') ?: $request->ip()));
        });
        // plugin-ai: Insightistic Cloud AI calls proxy to a real AI provider,
        // so this is deliberately tighter than the connector limiter above —
        // matches the plugin's own client-side ~60s cost-guardrail lock.
        \Illuminate\Support\Facades\RateLimiter::for('plugin-ai', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(6)
                ->by('conn-ai:' . ($request->header('X-INS-Key-Id') ?: $request->ip()));
        });
        // public: unauthenticated, cacheable, low-value-per-request endpoints
        // (e.g. the public plans manifest) — generous, per-IP only.
        \Illuminate\Support\Facades\RateLimiter::for('public', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->ip());
        });
        // ai-chat: Copilot messages — each one is a real model call.
        \Illuminate\Support\Facades\RateLimiter::for('ai-chat', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(20)
                ->by('ai-chat:' . ($request->user()?->id ?: $request->ip()));
        });
        // knowledge-upload: file/manual source creation (parsing + embedding cost).
        \Illuminate\Support\Facades\RateLimiter::for('knowledge-upload', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(20)
                ->by('kb-up:' . ($request->user()?->id ?: $request->ip()));
        });
        // knowledge-crawl: URL/sitemap ingestion — outbound crawling is the
        // most abusable surface (SSRF probing), so it is the tightest.
        \Illuminate\Support\Facades\RateLimiter::for('knowledge-crawl', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(6)
                ->by('kb-crawl:' . ($request->user()?->id ?: $request->ip()));
        });
        // exports: bounded CSV generation still hits the database hard.
        \Illuminate\Support\Facades\RateLimiter::for('exports', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)
                ->by('exports:' . ($request->user()?->id ?: $request->ip()));
        });
        // query-engine: Safe WooCommerce Query Engine (spec Phase 2 / P1).
        // Per-user on top of QueryConcurrencyLimiter's per-org in-flight cap
        // — this bounds request *rate*, concurrency limits bound simultaneous execution.
        \Illuminate\Support\Facades\RateLimiter::for('query-engine', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(30)
                ->by('query-engine:' . ($request->user()?->id ?: $request->ip()));
        });

        /*
         * Queue worker heartbeat (spec Phase 2.3). Recording on JobProcessing
         * means the signal costs one cache write per job instead of a separate
         * supervisor process, and it proves a worker is actually *consuming*
         * rather than merely running — a worker stuck on a poisoned job is
         * alive to `ps` and useless to the queue.
         */
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobProcessing::class,
            fn () => \App\Services\Observability\QueueHealth::recordHeartbeat(),
        );

        // Password reset / invite links should open the SPA, not a web route.
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function ($user, string $token) {
            $app = rtrim(config('insightistic.app_url'), '/');
            return $app . '/reset-password?token=' . $token . '&email=' . urlencode($user->getEmailForPasswordReset());
        });

        // Register the Microsoft Socialite provider (Google + GitHub ship with
        // Socialite core). Guarded so the app still boots if the optional
        // package isn't installed yet.
        if (class_exists(\SocialiteProviders\Microsoft\MicrosoftExtendSocialite::class)) {
            \Illuminate\Support\Facades\Event::listen(
                \SocialiteProviders\Manager\SocialiteWasCalled::class,
                [\SocialiteProviders\Microsoft\MicrosoftExtendSocialite::class, 'handle'],
            );
        }
    }
}
