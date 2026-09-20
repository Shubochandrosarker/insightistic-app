<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'stripe_customer_id', 'stripe_subscription_id',
        'plan_id', 'status', 'current_period_start', 'current_period_end',
        'cancel_at_period_end',
        'internal_override_active', 'internal_override_reason',
        'internal_override_by', 'internal_override_at',
        'billing_provider', 'provider_customer_id', 'provider_subscription_id',
        'provider_price_id', 'provider_status', 'next_billed_at',
        'scheduled_change_action', 'scheduled_change_at',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start'     => 'datetime',
            'current_period_end'       => 'datetime',
            'cancel_at_period_end'     => 'boolean',
            'internal_override_active' => 'boolean',
            'internal_override_at'     => 'datetime',
            'billing_provider'         => 'string',
            'provider_customer_id'     => 'string',
            'provider_subscription_id' => 'string',
            'provider_price_id'        => 'string',
            'provider_status'          => 'string',
            'next_billed_at'           => 'datetime',
            'scheduled_change_action'  => 'string',
            'scheduled_change_at'      => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_override_by');
    }
}
