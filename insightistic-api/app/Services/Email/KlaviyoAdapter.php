<?php

namespace App\Services\Email;

use App\Models\Site;
use Illuminate\Support\Facades\Http;

/**
 * Klaviyo API (JSON:API era — requires a `revision` header).
 *
 * Klaviyo splits campaign metadata from campaign statistics: /campaigns
 * lists what was sent, and the campaign-values report returns the funnel.
 * Both are needed, so this adapter lists first and then asks for statistics
 * keyed by campaign id.
 *
 * Klaviyo reports deliveries, opens and clicks as separate statistics rather
 * than a single funnel object, and omits any it has no data for — those stay
 * null so the dashboard shows "not reported" rather than a fabricated zero.
 */
class KlaviyoAdapter implements EmailProviderAdapter
{
    private const BASE = 'https://a.klaviyo.com/api';
    private const REVISION = '2024-10-15';

    public function key(): string
    {
        return 'klaviyo';
    }

    public function label(): string
    {
        return 'Klaviyo';
    }

    public function verify(string $credential): string
    {
        $response = $this->request($credential, 'accounts');

        if (! $response->successful()) {
            throw new EmailProviderFailure('invalid_credential', 'Klaviyo rejected this private API key.');
        }

        $account = $response->json()['data'][0]['attributes'] ?? [];

        return (string) ($account['contact_information']['organization_name'] ?? 'Klaviyo account');
    }

    public function fetchCampaigns(Site $site, string $credential, int $limit = 50): array
    {
        $list = $this->request($credential, 'campaigns', [
            // Email only: Klaviyo also returns SMS/push campaigns here.
            'filter'   => "equals(messages.channel,'email')",
            'sort'     => '-send_time',
            'page[size]' => min($limit, 100),
        ]);

        if (! $list->successful()) {
            $this->fail($list, 'klaviyo_campaigns_failed');
        }

        $campaigns = $list->json()['data'] ?? [];
        if ($campaigns === []) {
            return [];
        }

        $statistics = $this->statistics($credential, array_map(fn ($c) => (string) $c['id'], $campaigns));

        return array_values(array_filter(array_map(
            fn (array $campaign) => $this->normalise($campaign, $statistics[$campaign['id']] ?? []),
            $campaigns,
        )));
    }

    /**
     * Campaign-values report, keyed by campaign id.
     *
     * A failure here is not fatal: campaign metadata is still worth showing
     * with empty counters, which the UI renders as "not reported".
     *
     * @return array<string,array<string,mixed>>
     */
    private function statistics(string $credential, array $campaignIds): array
    {
        $response = Http::withHeaders($this->headers($credential))
            ->timeout(config('insightistic.email.timeout'))
            ->post(self::BASE . '/campaign-values-reports', [
                'data' => [
                    'type'       => 'campaign-values-report',
                    'attributes' => [
                        'statistics' => [
                            'recipients', 'delivered', 'bounced', 'opens', 'opens_unique',
                            'clicks', 'clicks_unique', 'unsubscribes', 'spam_complaints',
                            'conversions', 'conversion_value',
                        ],
                        'timeframe' => ['key' => 'last_12_months'],
                        'filter'    => 'any(campaign_id,["' . implode('","', $campaignIds) . '"])',
                    ],
                ],
            ]);

        if (! $response->successful()) {
            logger()->warning('Klaviyo campaign-values report unavailable; showing metadata only.', [
                'status' => $response->status(),
            ]);

            return [];
        }

        $out = [];
        foreach ($response->json()['data']['attributes']['results'] ?? [] as $row) {
            $id = (string) ($row['groupings']['campaign_id'] ?? '');
            if ($id !== '') {
                $out[$id] = $row['statistics'] ?? [];
            }
        }

        return $out;
    }

    private function normalise(array $campaign, array $stats): ?array
    {
        $id = (string) ($campaign['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $attributes = $campaign['attributes'] ?? [];

        return [
            'provider'      => $this->key(),
            'external_id'   => $id,
            'name'          => (string) ($attributes['name'] ?? 'Untitled campaign'),
            'subject'       => null, // lives on the message resource, not the campaign
            'type'          => 'campaign',
            'status'        => $this->status((string) ($attributes['status'] ?? '')),
            'sent_at'       => $this->date($attributes['send_time'] ?? null),
            'recipients'    => $this->int($stats, 'recipients'),
            'sent'          => $this->int($stats, 'recipients'),
            'delivered'     => $this->int($stats, 'delivered'),
            'bounced'       => $this->int($stats, 'bounced'),
            'opened'        => $this->int($stats, 'opens'),
            'unique_opens'  => $this->int($stats, 'opens_unique'),
            'clicked'       => $this->int($stats, 'clicks'),
            'unique_clicks' => $this->int($stats, 'clicks_unique'),
            'unsubscribed'  => $this->int($stats, 'unsubscribes'),
            'complained'    => $this->int($stats, 'spam_complaints'),
            'conversions'   => $this->int($stats, 'conversions'),
            'revenue'       => isset($stats['conversion_value']) ? round((float) $stats['conversion_value'], 2) : null,
            'currency'      => null, // Klaviyo reports value in the account currency
        ];
    }

    private function status(string $klaviyoStatus): string
    {
        return match (strtolower($klaviyoStatus)) {
            'draft'            => 'draft',
            'scheduled', 'queued' => 'scheduled',
            'sending'          => 'sending',
            'cancelled', 'paused' => 'paused',
            default            => 'sent',
        };
    }

    private function request(string $credential, string $path, array $query = [])
    {
        return Http::withHeaders($this->headers($credential))
            ->timeout(config('insightistic.email.timeout'))
            ->get(self::BASE . '/' . ltrim($path, '/'), $query);
    }

    private function headers(string $credential): array
    {
        return [
            'Authorization' => 'Klaviyo-API-Key ' . $credential,
            'revision'      => self::REVISION,
            'accept'        => 'application/vnd.api+json',
            'content-type'  => 'application/vnd.api+json',
        ];
    }

    private function fail($response, string $code): never
    {
        $message = (string) ($response->json()['errors'][0]['detail'] ?? 'Klaviyo request failed.');

        throw new EmailProviderFailure(
            in_array($response->status(), [401, 403], true) ? 'invalid_credential' : $code,
            $message,
            retryable: $response->status() === 429 || $response->status() >= 500,
        );
    }

    private function int(array $source, string $key): ?int
    {
        return isset($source[$key]) ? (int) round((float) $source[$key]) : null;
    }

    private function date(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
