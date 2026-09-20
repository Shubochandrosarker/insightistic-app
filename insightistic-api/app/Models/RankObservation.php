<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point-in-time SERP observation of one tracked keyword (Phase 10,
 * doc 13 §Domain model). Append-only history: a row is written once by
 * RankChecker after a successful provider call and never updated — the
 * latest known position lives in the newest row, and a provider failure
 * simply writes nothing (doc 13 acceptance: a failure must not overwrite
 * the last known position).
 *
 * position NULL means the tracked site's domain was not found in the
 * provider's top 100 for that check — it is a real observation ("not
 * ranking"), not missing data, and feeds the rank_not_found alert.
 *
 * Grain-style timestamps: created_at set manually at write time, no
 * updated_at — observations are immutable facts.
 */
class RankObservation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'keyword_id', 'site_id', 'checked_at', 'position', 'ranking_url',
        'serp_features_json', 'provider', 'provider_request_id', 'cost_units',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at'         => 'datetime',
            'created_at'         => 'datetime',
            'serp_features_json' => 'array',
            'cost_units'         => 'decimal:4',
        ];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(RankKeyword::class, 'keyword_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
