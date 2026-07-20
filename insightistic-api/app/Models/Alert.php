<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    public const TYPES = [
        'revenue_drop', 'refund_spike', 'failed_order_spike', 'low_stock',
        'traffic_drop', 'traffic_spike', 'seo_decline', 'performance_regression',
        'sync_stale', 'sync_failed', 'broken_link_spike',
    ];

    protected $fillable = ['site_id', 'type', 'title', 'message', 'severity', 'is_resolved'];

    protected function casts(): array
    {
        return ['is_resolved' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
