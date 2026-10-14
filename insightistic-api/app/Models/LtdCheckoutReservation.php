<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LtdCheckoutReservation extends Model
{
    protected $fillable = [
        'campaign_id', 'organization_id', 'plan_slug', 'idempotency_key',
        'checkout_id', 'provider_transaction_id', 'status', 'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
