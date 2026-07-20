<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementDaily extends Model
{
    // Same pluralization mismatch as TrafficDaily — default guess is
    // "engagement_dailies", real table is singular "engagement_daily".
    protected $table = 'engagement_daily';

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
