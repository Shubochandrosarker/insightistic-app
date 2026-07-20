<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'price_monthly', 'price_yearly',
        'stripe_price_id_monthly', 'stripe_price_id_yearly',
        'site_limit', 'user_limit', 'ai_insight_limit', 'report_limit',
        'white_label_enabled', 'custom_domain_enabled', 'features_json',
        'is_active', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'white_label_enabled'   => 'boolean',
            'custom_domain_enabled' => 'boolean',
            'features_json'         => 'array',
            'is_active'             => 'boolean',
            'is_featured'           => 'boolean',
        ];
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
