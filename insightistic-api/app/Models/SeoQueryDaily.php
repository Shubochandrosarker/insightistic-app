<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily Search Console grain: one row per (site, date, query). The hash of
 * the stored query is the unique key (App\Support\SeoGrain::hash), so pulls
 * and backfills replay without duplicating rows (doc 08).
 */
class SeoQueryDaily extends Model
{
    // Same pluralization mismatch as SeoDaily — default guess is
    // "seo_query_dailies", real table is singular "seo_query_daily".
    protected $table = 'seo_query_daily';

    public $timestamps = false;

    protected $fillable = [
        'site_id', 'date', 'query_hash', 'query',
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
