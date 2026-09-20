<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class BillingTransaction extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'provider', 'provider_transaction_id',
        'provider_subscription_id', 'provider_price_id', 'kind', 'status',
        'currency', 'subtotal_minor', 'tax_minor', 'total_minor',
        'invoice_number', 'occurred_at', 'completed_at', 'summary_json',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at'   => 'datetime',
            'completed_at'  => 'datetime',
            'summary_json'  => 'array',
        ];
    }
}
