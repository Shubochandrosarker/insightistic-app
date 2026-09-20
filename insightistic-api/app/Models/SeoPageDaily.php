<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily Search Console grain: one row per (site, date, page). `page_path` is
 * the normalized path (no scheme/host/query — same rule as the legacy page
 * snapshots) and its hash (App\Support\SeoGrain::hash) is the unique key.
 */
class SeoPageDaily extends Model
{
    // Same pluralization mismatch as SeoDaily — default guess is
    // "seo_page_dailies", real table is singular "seo_page_daily".
    protected $table = 'seo_page_daily';

    public $timestamps = false;

    protected $fillable = [
        'site_id', 'date', 'page_hash', 'page_path',
        'clicks', 'impressions', 'ctr', 'position', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'date'       => 'date',
            'ctr'        => 'float',
            'position'   => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
