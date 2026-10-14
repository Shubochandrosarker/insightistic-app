<?php

namespace App\Providers;

use App\Metrics\MetricRegistry;
use App\Query\QueryConcurrencyLimiter;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiRuntimeSettings;
use App\Services\Ai\AiTierResolver;
use App\Services\Ai\OpenRouterProvider;
use App\Services\Ai\PlanAwareAiProvider;
use App\Services\Ai\RuleProvider;
use App\Services\Ai\WpisticGatewayClient;
use App\Services\Ai\WpisticGatewayProvider;
use App\Services\Knowledge\EmbeddingProvider;
use App\Services\Knowledge\NullEmbeddingProvider;
use App\Services\Knowledge\OpenAiEmbeddingProvider;
use App\Services\Knowledge\SafeUrlFetcher;
use App\Services\Observability\MeteredAiProvider;
use App\Services\Observability\QueueHealth;
use App\Services\Observability\Telemetry;
use App\Services\Observability\Tracing;
use App\Support\Tenancy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One Tenancy context per request.
        $this->app->scoped(Tenancy::class, fn () => new Tenancy);

        // Observability (spec Phase 2.3). Singletons because Tracing holds the
        // exporter's batch processor — one per process, not one per resolve,
        // or spans end up split across providers that each flush separately.
        $this->app->singleton(Tracing::class);
        $this->app->singleton(Telemetry::class);
        $this->app->singleton(QueueHealth::class);

        // One plan-aware routing policy shared by insights, reports, Ask,
        // AI visibility, and investigations. Every external attempt is
        // independently metered so fallback behavior is visible in ops.
        $this->app->bind(AiProvider::class, function () {
            $runtime = $this->app->make(AiRuntimeSettings::class)->resolved();
            $gatewayConfig = $runtime['gateway'];
            $routerConfig = $runtime['openrouter'];
            $telemetry = $this->app->make(Telemetry::class);
            $tracing = $this->app->make(Tracing::class);
            $meter = fn (AiProvider $provider, string $name) => new MeteredAiProvider(
                $provider,
                $telemetry,
                $tracing,
                $name,
            );

            $gatewayConfigured = ! empty($gatewayConfig['product_key']);
            $routerConfigured = ! empty($routerConfig['api_key']);
            $gateway = $gatewayConfigured
                ? fn () => $meter(new WpisticGatewayProvider($this->app->make(WpisticGatewayClient::class)), 'wpistic_gateway')
                : null;

            $free = [];
            $paid = [];

            if ($gateway) {
                $free[] = $gateway();
            }

            if ($routerConfigured) {
                $free[] = $meter(new OpenRouterProvider($routerConfig + [
                    'model' => $routerConfig['free_model'],
                    'fallback_models' => $routerConfig['free_fallback_models'],
                    'reasoning_effort' => $routerConfig['free_reasoning_effort'] ?? 'low',
                    'require_parameters' => true,
                ]), 'openrouter');
                $paid[] = $meter(new OpenRouterProvider($routerConfig + [
                    'model' => $routerConfig['paid_model'],
                    'fallback_models' => $routerConfig['paid_fallback_models'],
                    'require_parameters' => true,
                ]), 'openrouter');
            }

            // Keep the shared WPistic brain first for every billing tier.
            // OpenRouter is a second provider, never the first hop.
            if ($gateway) {
                array_unshift($paid, $gateway());
            }

            $rule = $meter(new RuleProvider, 'rule');
            $free[] = $rule;
            $paid[] = $rule;

            return new PlanAwareAiProvider($free, $paid, $this->app->make(AiTierResolver::class));
        });

        // Embedding provider for the knowledge base. 'none' (or a missing
        // key) binds the null provider: retrieval degrades to full-text
        // search — real results, no fabricated vectors.
        $this->app->bind(EmbeddingProvider::class, function () {
            $cfg = config('insightistic.embeddings');

            return ($cfg['provider'] ?? 'none') === 'openai' && ! empty($cfg['api_key'])
                ? new OpenAiEmbeddingProvider($cfg)
                : new NullEmbeddingProvider;
        });

        // SSRF-guarded fetcher with configured crawl limits.
        $this->app->bind(SafeUrlFetcher::class, function () {
            return new SafeUrlFetcher(
                (int) config('insightistic.knowledge.crawl_timeout', 20),
                (int) config('insightistic.knowledge.crawl_max_bytes', 10_485_760),
            );
        });

        // Semantic Metric Registry (spec Phase 1): one versioned catalog of
        // canonical metric definitions. Singleton because the catalog is
        // static per-process — no request-scoped state, no DB round trip.
        $this->app->singleton(MetricRegistry::class);

        // Safe WooCommerce Query Engine (spec Phase 2 / P1).
        $this->app->bind(QueryConcurrencyLimiter::class, function () {
            return new QueryConcurrencyLimiter(
                (int) config('insightistic.query_engine.max_concurrent_per_org', 3),
                (int) config('insightistic.query_engine.concurrency_lock_ttl_seconds', 30),
            );
        });
    }

    public function boot(): void
    {
        // Named rate limiters (attached in routes/api.php as throttle:<name>).
        // auth: brute-force protection on credential endpoints.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
        // license: plugin activation/validation — per IP and per key prefix so
        // a single attacker can't enumerate keys from many source IPs cheaply.
        RateLimiter::for('license', function (Request $request) {
            $key = substr((string) $request->input('license_key', ''), 0, 24);

            return [
                Limit::perMinute(30)->by('lic-ip:'.$request->ip()),
                Limit::perMinute(60)->by('lic-key:'.sha1($key)),
            ];
        });
        // connector: HMAC sync traffic, per key id.
        RateLimiter::for('connector', function (Request $request) {
            return Limit::perMinute(120)
                ->by('conn:'.($request->header('X-INS-Key-Id') ?: $request->ip()));
        });
        // plugin-ai: Insightistic Cloud AI calls proxy to a real AI provider,
        // so this is deliberately tighter than the connector limiter above —
        // matches the plugin's own client-side ~60s cost-guardrail lock.
        RateLimiter::for('plugin-ai', function (Request $request) {
            return Limit::perMinute(6)
                ->by('conn-ai:'.($request->header('X-INS-Key-Id') ?: $request->ip()));
        });
        // public: unauthenticated, cacheable, low-value-per-request endpoints
        // (e.g. the public plans manifest) — generous, per-IP only.
        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
        // ai-chat: Copilot messages — each one is a real model call.
        RateLimiter::for('ai-chat', function (Request $request) {
            return Limit::perMinute(20)
                ->by('ai-chat:'.($request->user()?->id ?: $request->ip()));
        });
        // knowledge-upload: file/manual source creation (parsing + embedding cost).
        RateLimiter::for('knowledge-upload', function (Request $request) {
            return Limit::perMinute(20)
                ->by('kb-up:'.($request->user()?->id ?: $request->ip()));
        });
        // knowledge-crawl: URL/sitemap ingestion — outbound crawling is the
        // most abusable surface (SSRF probing), so it is the tightest.
        RateLimiter::for('knowledge-crawl', function (Request $request) {
            return Limit::perMinute(6)
                ->by('kb-crawl:'.($request->user()?->id ?: $request->ip()));
        });
        // exports: bounded CSV generation still hits the database hard.
        RateLimiter::for('exports', function (Request $request) {
            return Limit::perMinute(10)
                ->by('exports:'.($request->user()?->id ?: $request->ip()));
        });
        // query-engine: Safe WooCommerce Query Engine (spec Phase 2 / P1).
        // Per-user on top of QueryConcurrencyLimiter's per-org in-flight cap
        // — this bounds request *rate*, concurrency limits bound simultaneous execution.
        RateLimiter::for('query-engine', function (Request $request) {
            return Limit::perMinute(30)
                ->by('query-engine:'.($request->user()?->id ?: $request->ip()));
        });

        /*
         * Queue worker heartbeat (spec Phase 2.3). Recording on JobProcessing
         * means the signal costs one cache write per job instead of a separate
         * supervisor process, and it proves a worker is actually *consuming*
         * rather than merely running — a worker stuck on a poisoned job is
         * alive to `ps` and useless to the queue.
         */
        Event::listen(
            JobProcessing::class,
            fn () => QueueHealth::recordHeartbeat(),
        );

        // Password reset / invite links should open the SPA, not a web route.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $app = rtrim(config('insightistic.app_url'), '/');

            return $app.'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset());
        });

        // Register the Microsoft Socialite provider (Google + GitHub ship with
        // Socialite core). Guarded so the app still boots if the optional
        // package isn't installed yet.
        if (class_exists(MicrosoftExtendSocialite::class)) {
            Event::listen(
                SocialiteWasCalled::class,
                [MicrosoftExtendSocialite::class, 'handle'],
            );
        }
    }
}
