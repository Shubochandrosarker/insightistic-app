<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoQuerySnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id', 'source', 'period_start', 'period_end', 'query',
        'clicks', 'impressions', 'ctr', 'position',
        'avg_click_position', 'avg_impression_position',
        'prev_clicks', 'prev_impressions', 'prev_ctr', 'prev_position',
        'opportunity', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end'   => 'date',
            'ctr'          => 'float',
            'position'     => 'float',
            'avg_click_position'      => 'float',
            'avg_impression_position' => 'float',
            'prev_ctr'     => 'float',
            'prev_position' => 'float',
            'created_at'   => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
