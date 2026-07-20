<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceRun extends Model
{
    protected $fillable = [
        'site_id', 'url', 'strategy', 'run_at',
        'lighthouse_version', 'fetch_time',
        'performance_score', 'accessibility_score', 'best_practices_score', 'seo_score', 'ai_readiness_score',
        'lcp_ms', 'lcp_status', 'inp_ms', 'inp_status', 'cls', 'cls_status',
        'fcp_ms', 'tbt_ms', 'speed_index_ms', 'ttfb_ms',
        'field_data_available', 'lab_data_available',
        'status', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'run_at'                => 'datetime',
            'fetch_time'            => 'datetime',
            'cls'                   => 'float',
            'field_data_available'  => 'boolean',
            'lab_data_available'    => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(PerformanceIssue::class);
    }
}
