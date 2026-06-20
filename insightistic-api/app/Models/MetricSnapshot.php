<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One precomputed row per (site, local-date). Keyed by site_id (not org) because
 * a site already belongs to exactly one tenant and all access is gated through
 * the tenant-scoped Site lookup.
 */
class MetricSnapshot extends Model
{
    protected $fillable = [
        'site_id', 'date', 'revenue', 'orders', 'refunds', 'average_order_value',
        'new_customers', 'returning_customers', 'products_sold', 'failed_orders',
    ];

    protected function casts(): array
    {
        return [
            'date'                => 'date',
            'revenue'             => 'decimal:2',
            'average_order_value' => 'decimal:2',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
