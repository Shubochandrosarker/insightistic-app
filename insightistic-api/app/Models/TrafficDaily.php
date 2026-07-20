<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficDaily extends Model
{
    // Without this, Eloquent's default pluralization guesses "traffic_dailies"
    // (Doctrine Inflector treats "Daily" as a y->ies plural noun) — the real
    // migration is singular "traffic_daily". Caught by a live migration +
    // real-write smoke test (Milestone 3); nothing had ever written through
    // this model before, so the mismatch was silent until now.
    protected $table = 'traffic_daily';

    protected $fillable = [
        'site_id', 'date', 'source',
        'users', 'new_users', 'sessions', 'engaged_sessions', 'page_views',
        'engagement_rate', 'bounce_rate', 'avg_engagement_duration_seconds',
        'conversions', 'transactions', 'revenue',
    ];

    protected function casts(): array
    {
        return [
            'date'             => 'date',
            'engagement_rate'  => 'float',
            'bounce_rate'      => 'float',
            'revenue'          => 'float',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
