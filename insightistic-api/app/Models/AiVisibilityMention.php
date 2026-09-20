<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entity observed in one AI-visibility answer (Phase 11, doc 14): the
 * owned brand or a competitor, its order of appearance and a heuristic
 * sentiment (see MentionExtractor — naive near-word classification,
 * documented as such; never presented as provider ground truth).
 * One row per entity per run — the entity's FIRST appearance fixes its
 * position_order.
 *
 * Grain-style timestamps: created_at set manually at write time.
 */
class AiVisibilityMention extends Model
{
    public const SENTIMENT_POSITIVE = 'positive';

    public const SENTIMENT_NEUTRAL = 'neutral';

    public const SENTIMENT_NEGATIVE = 'negative';

    public $timestamps = false;

    protected $fillable = [
        'run_id', 'site_id', 'entity_name', 'is_owned', 'is_competitor',
        'position_order', 'sentiment', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_owned'       => 'boolean',
            'is_competitor'  => 'boolean',
            'created_at'     => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiVisibilityRun::class, 'run_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
