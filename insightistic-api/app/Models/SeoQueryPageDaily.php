<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily Search Console grain, combined dimension: one row per
 * (site, date, query, page). Joins seo_query_daily and seo_page_daily on
 * their hash columns; hashes come from App\Support\SeoGrain::hash.
 */
class SeoQueryPageDaily extends Model
{
    // Same pluralization mismatch as SeoDaily — default guess is
    // "seo_query_page_dailies", real table is singular "seo_query_page_daily".
    protected $table = 'seo_query_page_daily';

    public $timestamps = false;

    protected $fillable = [
        'site_id', 'date', 'query_hash', 'page_hash',
        'clicks', 'impressions', 'position', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'date'       => 'date',
            'position'   => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
