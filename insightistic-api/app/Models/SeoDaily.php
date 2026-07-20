<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoDaily extends Model
{
    // Same pluralization mismatch as TrafficDaily — default guess is
    // "seo_dailies", real table is singular "seo_daily".
    protected $table = 'seo_daily';

    protected $fillable = ['site_id', 'date', 'clicks', 'impressions', 'ctr', 'avg_position'];

    protected function casts(): array
    {
        return [
            'date'         => 'date',
            'ctr'          => 'float',
            'avg_position' => 'float',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
