<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficDimensionSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id', 'period_start', 'period_end', 'source',
        'dimension_type', 'dimension_value', 'url', 'title',
        'sessions', 'users', 'views', 'conversions', 'revenue',
        'engagement_rate', 'rank', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start'    => 'date',
            'period_end'      => 'date',
            'revenue'         => 'float',
            'engagement_rate' => 'float',
            'created_at'      => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
