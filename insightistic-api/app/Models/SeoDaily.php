<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoDaily extends Model
{
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
