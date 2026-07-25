<?php

namespace App\Services\Email;

use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\EmailCampaign;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Connect/verify/sync for email providers, and the aggregation the Emails
 * page reads (spec Phase 3.4).
 *
 * Credentials reuse `data_source_credentials` with source='email', so email
 * providers inherit the same encrypted-at-rest, never-serialised handling as
 * the Google and Cloudflare grants rather than inventing a second store. The
 * chosen provider lives in the credential's `provider` column.
 *
 * Connector-backed providers (FluentCRM, AutomateWoo, WooCommerce) have no
 * credential here at all — the plugin pushes their rows over HMAC, and their
 * presence is inferred from the campaigns that arrive.
 */
class EmailAnalyticsService
{
    /** @var array<string,EmailProviderAdapter> */
    private array $adapters;

    public function __construct(MailchimpAdapter $mailchimp, KlaviyoAdapter $klaviyo)
    {
        $this->adapters = [
            $mailchimp->key() => $mailchimp,
            $klaviyo->key()   => $klaviyo,
        ];
    }

    /** @return array<int,array{key:string,label:string}> */
    public function availableProviders(): array
    {
        return array_values(array_map(
            fn (EmailProviderAdapter $a) => ['key' => $a->key(), 'label' => $a->label()],
            $this->adapters,
        ));
    }

    public function adapter(string $provider): EmailProviderAdapter
    {
        return $this->adapters[$provider]
            ?? throw new EmailProviderFailure('unsupported_provider', "Unknown email provider: {$provider}.");
    }

    /** Verify a key, store it encrypted, and record the connection state. */
    public function connect(Site $site, string $provider, string $credential, int $userId): string
    {
        $account = $this->adapter($provider)->verify($credential);

        DataSourceCredential::withoutGlobalScope('organization')->updateOrCreate(
            ['site_id' => $site->id, 'source' => 'email'],
            [
                'organization_id'      => $site->organization_id,
                'provider'             => $provider,
                'access_token'         => $credential,
                'refresh_token'        => null,
                'token_expires_at'     => null,
                'external_account_id'  => $account,
                'resource_json'        => ['id' => $provider, 'name' => $this->adapter($provider)->label()],
                'connected_by_user_id' => $userId,
            ],
        );

        $this->recordState($site, 'connected', [
            'label'              => $this->adapter($provider)->label() . ' — ' . $account,
            'last_error_code'    => null,
            'last_error_message' => null,
        ]);

        return $account;
    }

    public function disconnect(Site $site): void
    {
        $this->credential($site)?->delete();

        $this->recordState($site, 'not_configured', [
            'label'              => null,
            'last_error_code'    => null,
            'last_error_message' => null,
            'last_successful_at' => null,
            'latest_data_at'     => null,
            'freshness'          => 'unknown',
        ]);
    }

    public function credential(Site $site): ?DataSourceCredential
    {
        return DataSourceCredential::where('site_id', $site->id)->where('source', 'email')->first();
    }

    public function isConnected(Site $site): bool
    {
        return $this->credential($site) !== null;
    }

    /**
     * Pull campaigns from the connected API provider and upsert them.
     *
     * @return array{provider:string,campaigns:int,latest_sent_at:?string}
     */
    public function sync(Site $site, int $limit = 50): array
    {
        $credential = $this->credential($site);

        if (! $credential) {
            throw new EmailProviderFailure('not_connected', 'Connect an email platform for this site first.');
        }

        $this->recordState($site, 'syncing', ['last_attempted_at' => now()]);

        try {
            $rows = $this->adapter($credential->provider)
                ->fetchCampaigns($site, (string) $credential->access_token, $limit);

            $latest = $this->store($site, $rows);

            $this->recordState($site, 'connected', [
                'last_successful_at' => now(),
                'latest_data_at'     => $latest,
                'freshness'          => $this->freshness($latest),
                'last_error_code'    => null,
                'last_error_message' => null,
            ]);

            return [
                'provider'       => $credential->provider,
                'campaigns'      => count($rows),
                'latest_sent_at' => $latest?->toIso8601String(),
            ];
        } catch (EmailProviderFailure $e) {
            $this->recordState($site, $e->errorCode === 'invalid_credential' ? 'error' : 'degraded', [
                'last_attempted_at'  => now(),
                'last_error_code'    => $e->errorCode,
                'last_error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Upsert normalised rows for a site. Shared by the API adapters and the
     * connector endpoint, so both paths dedupe identically.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return \Illuminate\Support\Carbon|null newest send time seen
     */
    public function store(Site $site, array $rows): ?\Illuminate\Support\Carbon
    {
        $latest = null;

        DB::transaction(function () use ($rows, $site, &$latest) {
            foreach ($rows as $row) {
                if (empty($row['external_id']) || empty($row['provider'])) {
                    continue;
                }

                EmailCampaign::withoutGlobalScope('organization')->updateOrCreate(
                    [
                        'site_id'     => $site->id,
                        'provider'    => $row['provider'],
                        'external_id' => (string) $row['external_id'],
                    ],
                    array_merge(
                        \Illuminate\Support\Arr::except($row, ['provider', 'external_id']),
                        ['organization_id' => $site->organization_id],
                    ),
                );

                if (! empty($row['sent_at'])) {
                    $sentAt = \Illuminate\Support\Carbon::parse($row['sent_at']);
                    if ($latest === null || $sentAt->greaterThan($latest)) {
                        $latest = $sentAt;
                    }
                }
            }
        });

        return $latest;
    }

    /**
     * Totals across a window, plus which providers actually contributed.
     *
     * Sums stay null when no campaign reported that counter, so the UI can
     * show "—" instead of a confident zero.
     */
    public function overview(Site $site, int $days = 90): array
    {
        $since = now()->subDays($days);

        $campaigns = EmailCampaign::where('site_id', $site->id)
            ->where(fn ($q) => $q->where('sent_at', '>=', $since)->orWhereNull('sent_at'))
            ->get();

        $sum = fn (string $column) => $campaigns->whereNotNull($column)->isEmpty()
            ? null
            : (int) $campaigns->sum($column);

        $sent = $sum('sent');
        $delivered = $sum('delivered');
        $denominator = $delivered ?? $sent;

        $revenue = $campaigns->whereNotNull('revenue')->isEmpty()
            ? null
            : round((float) $campaigns->sum('revenue'), 2);

        $uniqueOpens = $sum('unique_opens') ?? $sum('opened');
        $uniqueClicks = $sum('unique_clicks') ?? $sum('clicked');

        return [
            'window_days' => $days,
            'campaigns'   => $campaigns->count(),
            'providers'   => $campaigns->pluck('provider')->unique()->values()->all(),
            'totals'      => [
                'sent'         => $sent,
                'delivered'    => $delivered,
                'bounced'      => $sum('bounced'),
                'opened'       => $uniqueOpens,
                'clicked'      => $uniqueClicks,
                'unsubscribed' => $sum('unsubscribed'),
                'conversions'  => $sum('conversions'),
                'revenue'      => $revenue,
            ],
            'rates' => [
                'delivery' => $this->rate($delivered, $sent),
                'open'     => $this->rate($uniqueOpens, $denominator),
                'click'    => $this->rate($uniqueClicks, $denominator),
                'bounce'   => $this->rate($sum('bounced'), $sent),
            ],
            'currency' => $campaigns->firstWhere('currency', '!=', null)?->currency,
        ];
    }

    private function rate(?int $numerator, ?int $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator === 0) {
            return null;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function freshness(?\Illuminate\Support\Carbon $latest): string
    {
        if (! $latest) {
            return 'unknown';
        }

        // Campaigns are episodic — a fortnight without a send is normal, so
        // this window is deliberately far wider than the analytics sources'.
        return $latest->diffInDays(now()) <= 30 ? 'fresh' : 'stale';
    }

    private function recordState(Site $site, string $state, array $attrs = []): void
    {
        DataSourceConnection::recordAttempt(
            $site->organization_id,
            $site->id,
            'email',
            array_merge(['state' => $state], $attrs),
        );
    }
}
