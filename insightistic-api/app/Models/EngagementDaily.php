<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementDaily extends Model
{
    protected $fillable = ['site_id', 'date', 'event_name', 'count'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
