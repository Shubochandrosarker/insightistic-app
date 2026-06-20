<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'price_monthly', 'price_yearly',
        'stripe_price_id_monthly', 'stripe_price_id_yearly',
        'site_limit', 'user_limit', 'ai_insight_limit', 'report_limit',
        'white_label_enabled', 'custom_domain_enabled', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'white_label_enabled'   => 'boolean',
            'custom_domain_enabled' => 'boolean',
            'is_active'             => 'boolean',
        ];
    }
}
