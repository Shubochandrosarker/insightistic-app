<?php

namespace App\Services\Analytics;

use App\Models\BillingTransaction;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Optional server-side purchase delivery for consented ad clicks. */
class ConversionDeliveryService
{
    public function deliver(BillingTransaction $transaction, Organization $organization): void
    {
        if (! $organization->marketing_consent) {
            return;
        }

        $touch = $organization->last_touch_json ?: $organization->first_touch_json ?: [];
        if (! is_array($touch)) {
            return;
        }

        if (($gclid = $touch['gclid'] ?? null) && config('insightistic.marketing.google_ads.enabled')) {
            $this->googleAds($transaction, (string) $gclid);
        }

        if (($fbclid = $touch['fbclid'] ?? null) && config('insightistic.marketing.meta.enabled')) {
            $this->meta($transaction, (string) $fbclid, $touch['landing_url'] ?? null);
        }
    }

    private function googleAds(BillingTransaction $transaction, string $gclid): void
    {
        $cfg = config('insightistic.marketing.google_ads');
        foreach (['access_token', 'developer_token', 'customer_id', 'conversion_action_id'] as $key) {
            if (empty($cfg[$key])) {
                Log::warning('Google Ads conversion delivery skipped: incomplete configuration.', ['missing' => $key]);

                return;
            }
        }

        $customer = preg_replace('/\D+/', '', (string) $cfg['customer_id']);
        $endpoint = rtrim((string) $cfg['endpoint'], '/') . "/v19/customers/{$customer}:uploadClickConversions";
        $headers = [
            'developer-token' => $cfg['developer_token'],
            'Content-Type' => 'application/json',
        ];
        if (! empty($cfg['login_customer_id'])) {
            $headers['login-customer-id'] = preg_replace('/\D+/', '', (string) $cfg['login_customer_id']);
        }

        Http::withToken($cfg['access_token'])
            ->withHeaders($headers)
            ->timeout(20)
            ->post($endpoint, [
                'conversions' => [[
                    'gclid' => $gclid,
                    'conversionAction' => "customers/{$customer}/conversionActions/{$cfg['conversion_action_id']}",
                    'conversionDateTime' => ($transaction->completed_at ?: now())->utc()->format('Y-m-d H:i:sP'),
                    'conversionValue' => ((int) $transaction->total_minor) / 100,
                    'currencyCode' => $transaction->currency,
                ]],
                'partialFailure' => true,
                'validateOnly' => false,
            ])->throw();
    }

    private function meta(BillingTransaction $transaction, string $fbclid, ?string $landingUrl): void
    {
        $cfg = config('insightistic.marketing.meta');
        if (empty($cfg['pixel_id']) || empty($cfg['access_token'])) {
            Log::warning('Meta conversion delivery skipped: incomplete configuration.');

            return;
        }

        $endpoint = rtrim((string) $cfg['endpoint'], '/') . '/' . rawurlencode((string) $cfg['pixel_id']) . '/events';
        Http::asJson()->timeout(20)->post($endpoint, [
            'access_token' => $cfg['access_token'],
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => ($transaction->completed_at ?: now())->timestamp,
                'event_id' => 'billing:' . $transaction->provider_transaction_id,
                'action_source' => 'website',
                'event_source_url' => is_string($landingUrl) && preg_match('#^https?://#i', $landingUrl) ? $landingUrl : null,
                'user_data' => ['fbc' => 'fb.1.' . now()->timestamp . '.' . $fbclid],
                'custom_data' => [
                    'value' => ((int) $transaction->total_minor) / 100,
                    'currency' => $transaction->currency,
                ],
            ]],
        ])->throw();
    }
}
