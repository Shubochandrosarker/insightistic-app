<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AppSumo Licensing API v2 license record (partner guide). One row per
 * AppSumo license_key — the system of record for LTD entitlements until the
 * buyer claims it through OAuth, at which point org_id/user_id/license_id
 * link it into the normal org-level `licenses` lifecycle.
 */
class AppSumoLicense extends Model
{
    // Explicit: Laravel's pluralizer would derive app_sumo_licenses.
    protected $table = 'appsumo_licenses';

    protected $fillable = [
        'license_key', 'event', 'license_status', 'tier',
        'prev_license_key', 'parent_license_key', 'partner_plan_name', 'unit_quantity',
        'plan_slug', 'org_id', 'user_id', 'license_id',
        'raw', 'event_timestamp', 'is_test',
    ];

    protected function casts(): array
    {
        return [
            'tier'            => 'integer',
            'unit_quantity'   => 'integer',
            'raw'             => 'array',
            'event_timestamp' => 'integer',
            'is_test'         => 'boolean',
        'keeps_tier_three_features' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
