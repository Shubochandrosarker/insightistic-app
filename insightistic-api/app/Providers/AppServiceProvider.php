<?php

namespace App\Providers;

use App\Services\Ai\AiProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\Ai\RuleProvider;
use App\Support\Tenancy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One Tenancy context per request.
        $this->app->scoped(Tenancy::class, fn () => new Tenancy());

        // AI provider: OpenAI when a key is configured, else the $0 rule-based
        // fallback so insights/reports work end-to-end without any AI spend.
        $this->app->bind(AiProvider::class, function () {
            $cfg = config('insightistic.ai');
            if (($cfg['provider'] ?? 'openai') === 'openai' && ! empty($cfg['api_key'])) {
                return new OpenAiProvider($cfg);
            }
            return new RuleProvider();
        });
    }

    public function boot(): void
    {
        // Password reset / invite links should open the SPA, not a web route.
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function ($user, string $token) {
            $app = rtrim(config('insightistic.app_url'), '/');
            return $app . '/reset-password?token=' . $token . '&email=' . urlencode($user->getEmailForPasswordReset());
        });
    }
}
