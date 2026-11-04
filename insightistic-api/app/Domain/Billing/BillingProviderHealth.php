<?php

namespace App\Domain\Billing;

use App\Services\Billing\PaddleClient;
use App\Services\StripeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → System Health for both billing providers. Status only — never key
 * material, webhook secrets or client tokens. API probes are cached for
 * five minutes so the health page can't hammer either provider.
 */
final class BillingProviderHealth
{
    public function __construct(
        private StripeService $stripe,
        private PaddleClient $paddle,
    ) {}

    public function report(): array
    {
        return ['paddle' => $this->paddle(), 'stripe' => $this->stripe()];
    }

    public function stripe(): array
    {
        $mode = StripeService::mode();
        $configured = $mode !== 'unconfigured';
        $last = Schema::hasTable('stripe_webhook_events')
            ? DB::table('stripe_webhook_events')->max('created_at') : null;
        $probe = $configured
            ? Cache::remember('billing-health:stripe', 300, fn () => $this->stripe->ping() ?? 'ok')
            : 'not_configured';

        return [
            'configured'                => $configured,
            'mode'                      => $configured ? $mode : null,
            'live_allowed'              => (bool) config('billing.stripe.allow_live'),
            'api'                       => $probe === 'ok' ? 'ok' : ($configured ? 'error' : 'not_configured'),
            'api_error'                 => in_array($probe, ['ok', 'not_configured'], true) ? null : $probe,
            'webhook_signing_configured' => StripeService::webhookSecret() !== '',
            'last_webhook_at'           => $last ? \Illuminate\Support\Carbon::parse($last)->toIso8601String() : null,
            'webhook_recent'            => $this->recent($last),
            'portal_configured'         => (bool) config('billing.stripe.portal_configuration'),
            'offered_at_checkout'       => in_array('stripe', (array) config('billing.checkout_providers', []), true),
        ];
    }

    public function paddle(): array
    {
        $configured = $this->paddle->configured();
        $last = Schema::hasTable('billing_events')
            ? DB::table('billing_events')->where('provider', 'paddle')->max('created_at') : null;
        $probe = $configured
            ? Cache::remember('billing-health:paddle', 300, fn () => $this->paddle->ping() ?? 'ok')
            : 'not_configured';

        return [
            'configured'                => $configured && ! empty(config('billing.paddle.webhook_secret')),
            'environment'               => config('billing.paddle.sandbox') ? 'sandbox' : 'production',
            'api'                       => $probe === 'ok' ? 'ok' : ($configured ? 'error' : 'not_configured'),
            'api_error'                 => in_array($probe, ['ok', 'not_configured'], true) ? null : $probe,
            'webhook_signing_configured' => ! empty(config('billing.paddle.webhook_secret')),
            'last_webhook_at'           => $last ? \Illuminate\Support\Carbon::parse($last)->toIso8601String() : null,
            'webhook_recent'            => $this->recent($last),
            'offered_at_checkout'       => in_array('paddle', (array) config('billing.checkout_providers', []), true),
        ];
    }

    private function recent($last): bool
    {
        return $last !== null
            && \Illuminate\Support\Carbon::parse($last)->gt(now()->subHours((int) config('billing.stripe.webhook_recent_hours', 72)));
    }
}
