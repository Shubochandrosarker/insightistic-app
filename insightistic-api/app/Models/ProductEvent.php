<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One funnel / product event (spec §33). Events are the AUDIENCE-MEASUREMENT
 * trail — props pass through a strict whitelist at write time, so no token,
 * password or customer PII can ever land here.
 */
class ProductEvent extends Model
{
    use BelongsToOrganization;

    /** The stable taxonomy — anything else is rejected by EventLogger. */
    public const EVENTS = [
        // acquisition funnel
        'signup_started', 'signup_completed',
        'onboarding_started', 'activation_achieved',
        // product milestones
        'site_added', 'site_imported',
        'gsc_import_started', 'gsc_import_completed',
        'gsc_connected', 'ga4_connected', 'bing_connected', 'cloudflare_connected',
        'wordpress_connected',
        'crawler_completed', 'first_insight_viewed',
        // monetization
        'trial_started', 'checkout_started',
        'purchase_completed', 'subscription_started', 'subscription_canceled',
        'ltd_purchased', 'credit_pack_purchased',
    ];

    /** Props whitelist (top-level keys); everything else is dropped. */
    public const PROP_KEYS = [
        'source', 'method', 'platform', 'domain', 'site_count', 'property_count',
        'provider', 'plan', 'plan_kind', 'price_id', 'currency', 'amount',
        'trigger', 'page_count', 'days', 'is_new', 'reason', 'checkout_kind',
        'utm_source', 'utm_medium', 'utm_campaign',
        'transaction_id', 'subscription_id', // idempotency keys for billing events
    ];

    protected $fillable = ['organization_id', 'user_id', 'event', 'props_json', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'props_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
