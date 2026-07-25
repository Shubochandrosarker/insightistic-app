<?php

namespace App\Services\Email;

use App\Models\Site;
use Illuminate\Support\Facades\Http;

/**
 * Mailchimp Marketing API v3.
 *
 * Reads /reports rather than /campaigns: one call returns the whole funnel
 * (sends, bounces, opens, clicks, unsubscribes and ecommerce revenue) for
 * campaigns that have actually gone out, which is exactly the row shape
 * email_campaigns wants. /campaigns would need a second request per campaign
 * to get the same numbers.
 *
 * The data centre is encoded in the API key suffix (`...-us14`), so no
 * separate region setting is needed.
 */
class MailchimpAdapter implements EmailProviderAdapter
{
    public function key(): string
    {
        return 'mailchimp';
    }

    public function label(): string
    {
        return 'Mailchimp';
    }

    public function verify(string $credential): string
    {
        // The root resource both requires auth and carries the account name,
        // so it validates the key and labels the connection in one call —
        // /ping would only do the former.
        $response = $this->request($credential, '');

        if (! $response->successful()) {
            throw new EmailProviderFailure('invalid_credential', 'Mailchimp rejected this API key.');
        }

        return (string) ($response->json()['account_name'] ?? 'Mailchimp account');
    }

    public function fetchCampaigns(Site $site, string $credential, int $limit = 50): array
    {
        $response = $this->request($credential, 'reports', [
            'count'     => min($limit, 100),
            'sort_field' => 'send_time',
            'sort_dir'  => 'DESC',
        ]);

        if (! $response->successful()) {
            $this->fail($response, 'mailchimp_reports_failed');
        }

        return array_values(array_filter(array_map(
            fn (array $report) => $this->normalise($report),
            $response->json()['reports'] ?? [],
        )));
    }

    /** Map one Mailchimp report onto email_campaigns columns. */
    private function normalise(array $r): ?array
    {
        $id = (string) ($r['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $bounces = $r['bounces'] ?? [];
        $opens = $r['opens'] ?? [];
        $clicks = $r['clicks'] ?? [];
        $ecommerce = $r['ecommerce'] ?? [];

        $sent = $this->int($r, 'emails_sent');
        // Mailchimp reports bounces by type; delivered is sends minus all of them.
        $bounced = $this->sum($bounces, ['hard_bounces', 'soft_bounces', 'syntax_errors']);

        return [
            'provider'     => $this->key(),
            'external_id'  => $id,
            'name'         => (string) ($r['campaign_title'] ?? $r['subject_line'] ?? 'Untitled campaign'),
            'subject'      => isset($r['subject_line']) ? (string) $r['subject_line'] : null,
            'type'         => ($r['type'] ?? 'regular') === 'automation' ? 'automation' : 'campaign',
            'status'       => 'sent',
            'sent_at'      => $this->date($r['send_time'] ?? null),
            'recipients'   => $this->int($r, 'emails_sent'),
            'sent'         => $sent,
            'delivered'    => ($sent !== null && $bounced !== null) ? max(0, $sent - $bounced) : null,
            'bounced'      => $bounced,
            'opened'       => $this->int($opens, 'opens_total'),
            'unique_opens' => $this->int($opens, 'unique_opens'),
            'clicked'      => $this->int($clicks, 'clicks_total'),
            'unique_clicks' => $this->int($clicks, 'unique_subscriber_clicks'),
            'unsubscribed' => $this->int($r, 'unsubscribed'),
            'complained'   => $this->int($r, 'abuse_reports'),
            'conversions'  => $this->int($ecommerce, 'total_orders'),
            'revenue'      => isset($ecommerce['total_revenue']) ? round((float) $ecommerce['total_revenue'], 2) : null,
            'currency'     => isset($ecommerce['currency_code']) ? substr((string) $ecommerce['currency_code'], 0, 3) : null,
        ];
    }

    private function request(string $credential, string $path, array $query = [])
    {
        return Http::withBasicAuth('key', $credential)
            ->timeout(config('insightistic.email.timeout'))
            ->get($this->base($credential) . '/' . ltrim($path, '/'), $query);
    }

    /** `abc123-us14` → `https://us14.api.mailchimp.com/3.0`. */
    private function base(string $credential): string
    {
        $dc = str_contains($credential, '-') ? substr(strrchr($credential, '-'), 1) : '';

        if ($dc === '' || ! preg_match('/^[a-z]{2}\d{1,3}$/', $dc)) {
            throw new EmailProviderFailure(
                'invalid_credential',
                'That does not look like a Mailchimp API key — it should end in a data centre suffix such as "-us14".',
            );
        }

        return "https://{$dc}.api.mailchimp.com/3.0";
    }

    private function fail($response, string $code): never
    {
        $message = (string) ($response->json()['detail'] ?? 'Mailchimp request failed.');

        throw new EmailProviderFailure(
            in_array($response->status(), [401, 403], true) ? 'invalid_credential' : $code,
            $message,
            retryable: $response->status() === 429 || $response->status() >= 500,
        );
    }

    private function int(array $source, string $key): ?int
    {
        return isset($source[$key]) ? (int) $source[$key] : null;
    }

    private function sum(array $source, array $keys): ?int
    {
        $present = array_filter($keys, fn ($k) => isset($source[$k]));

        return $present === [] ? null : (int) array_sum(array_map(fn ($k) => (int) $source[$k], $present));
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
