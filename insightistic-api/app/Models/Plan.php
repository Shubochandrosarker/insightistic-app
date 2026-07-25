<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /**
     * Defaults for limits_json keys when a plan predates the knowledge-base
     * entitlements (null column). Deliberately conservative — the seeded
     * plans override these upward (see PlanSeeder).
     */
    public const LIMIT_DEFAULTS = [
        'knowledge_bases'     => 1,
        'knowledge_sources'   => 10,
        'storage_mb'          => 50,
        'indexed_chunks'      => 2000,
        'monthly_embeddings'  => 5000,
        'monthly_ai_messages' => 50,
        'scheduled_reports'   => 4,
        'data_retention_days' => 365,
    ];

    protected $fillable = [
        'name', 'slug', 'price_monthly', 'price_yearly',
        'stripe_price_id_monthly', 'stripe_price_id_yearly',
        'site_limit', 'user_limit', 'ai_insight_limit', 'report_limit',
        'white_label_enabled', 'custom_domain_enabled', 'features_json',
        'is_active', 'is_featured', 'sort_order', 'limits_json',
    ];

    protected function casts(): array
    {
        return [
            'white_label_enabled'   => 'boolean',
            'custom_domain_enabled' => 'boolean',
            'features_json'         => 'array',
            'limits_json'           => 'array',
            'is_active'             => 'boolean',
            'is_featured'           => 'boolean',
        ];
    }

    /** Resolve a configurable limit; DB value wins, code default backstops. */
    public function limit(string $key): int
    {
        $value = $this->limits_json[$key] ?? self::LIMIT_DEFAULTS[$key] ?? 0;

        return (int) $value;
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
