<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoPageSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id', 'period_start', 'period_end', 'page_path',
        'clicks', 'impressions', 'ctr', 'position',
        'prev_clicks', 'prev_impressions', 'prev_ctr', 'prev_position',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start'  => 'date',
            'period_end'    => 'date',
            'ctr'           => 'float',
            'position'      => 'float',
            'prev_ctr'      => 'float',
            'prev_position' => 'float',
            'created_at'    => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
