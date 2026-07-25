<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email campaign or automation, from any provider.
 *
 * Rate helpers return null rather than 0 when the underlying counter is
 * missing, so the UI can say "not reported" instead of implying a real 0%.
 */
class EmailCampaign extends Model
{
    use BelongsToOrganization;

    /** Pulled by the SaaS over the provider's API. */
    public const API_PROVIDERS = ['mailchimp', 'klaviyo'];

    /** Pushed by the WordPress connector. */
    public const CONNECTOR_PROVIDERS = ['fluentcrm', 'automatewoo', 'woocommerce'];

    protected $fillable = [
        'organization_id', 'site_id', 'provider', 'external_id',
        'name', 'type', 'status', 'subject', 'sent_at',
        'recipients', 'sent', 'delivered', 'bounced',
        'opened', 'unique_opens', 'clicked', 'unique_clicks',
        'unsubscribed', 'complained',
        'conversions', 'revenue', 'currency', 'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'sent_at'       => 'datetime',
            'revenue'       => 'decimal:2',
            'metadata_json' => 'array',
        ];
    }

    public static function allProviders(): array
    {
        return array_merge(self::API_PROVIDERS, self::CONNECTOR_PROVIDERS);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Delivered/sent, as a percentage, or null when not reported. */
    public function deliveryRate(): ?float
    {
        return $this->rate($this->delivered, $this->sent);
    }

    /** Unique opens over delivered — the figure ESPs call "open rate". */
    public function openRate(): ?float
    {
        return $this->rate($this->unique_opens ?? $this->opened, $this->delivered ?? $this->sent);
    }

    public function clickRate(): ?float
    {
        return $this->rate($this->unique_clicks ?? $this->clicked, $this->delivered ?? $this->sent);
    }

    public function bounceRate(): ?float
    {
        return $this->rate($this->bounced, $this->sent);
    }

    public function unsubscribeRate(): ?float
    {
        return $this->rate($this->unsubscribed, $this->delivered ?? $this->sent);
    }

    private function rate(?int $numerator, ?int $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator === 0) {
            return null;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    public function toApiArray(): array
    {
        return [
            'id'           => $this->id,
            'provider'     => $this->provider,
            'external_id'  => $this->external_id,
            'name'         => $this->name,
            'type'         => $this->type,
            'status'       => $this->status,
            'subject'      => $this->subject,
            'sent_at'      => $this->sent_at?->toIso8601String(),
            'recipients'   => $this->recipients,
            'sent'         => $this->sent,
            'delivered'    => $this->delivered,
            'bounced'      => $this->bounced,
            'opened'       => $this->unique_opens ?? $this->opened,
            'clicked'      => $this->unique_clicks ?? $this->clicked,
            'unsubscribed' => $this->unsubscribed,
            'conversions'  => $this->conversions,
            'revenue'      => $this->revenue !== null ? (float) $this->revenue : null,
            'currency'     => $this->currency,
            'rates'        => [
                'delivery'    => $this->deliveryRate(),
                'open'        => $this->openRate(),
                'click'       => $this->clickRate(),
                'bounce'      => $this->bounceRate(),
                'unsubscribe' => $this->unsubscribeRate(),
            ],
        ];
    }
}
