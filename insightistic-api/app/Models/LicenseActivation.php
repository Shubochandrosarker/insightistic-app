<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseActivation extends Model
{
    protected $fillable = [
        'license_id', 'site_id', 'site_url', 'normalized_site_url', 'site_hash',
        'wp_version', 'php_version', 'plugin_version', 'woocommerce_active',
        'environment', 'status', 'activated_at', 'last_seen_at', 'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'woocommerce_active' => 'boolean',
            'activated_at'       => 'datetime',
            'last_seen_at'       => 'datetime',
            'deactivated_at'     => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withoutGlobalScope('organization');
    }
}
