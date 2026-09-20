<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    protected $fillable = [
        'plan_id', 'provider', 'kind', 'provider_product_id',
        'provider_price_id', 'currency', 'amount_minor', 'billing_interval',
        'is_active', 'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'is_active'     => 'boolean',
            'metadata_json' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
