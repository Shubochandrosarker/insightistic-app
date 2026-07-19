<?php

namespace App\Providers;

use App\Services\Ai\AiProvider;
use App\Services\Ai\OpenAiProvider;
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

        // AI provider: OpenAI or the wpistic-ai-gateway brain when fully
        // configured, else the $0 rule-based fallback so insights/reports
        // work end-to-end without any AI spend.
        $this->app->bind(AiProvider::class, function () {
            $cfg = config('insightistic.ai');
            return match ($cfg['provider'] ?? 'openai') {
                'wpistic_gateway' => ! empty(config('insightistic.wpistic_gateway.shared_secret'))
                    ? new WpisticGatewayProvider(config('insightistic.wpistic_gateway'))
                    : new RuleProvider(),
                'openai' => ! empty($cfg['api_key']) ? new OpenAiProvider($cfg) : new RuleProvider(),
                default => new RuleProvider(),
            };
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
