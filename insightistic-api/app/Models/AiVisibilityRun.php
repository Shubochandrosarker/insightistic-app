<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI-visibility observation (Phase 11, doc 14): the answer a provider
 * surface gave to one monitored prompt at one point in time. Append-only —
 * written once by PromptRunner and never updated; a provider failure writes
 * a status=failed row (isolation: the scheduled sweep continues with the
 * other prompts) rather than being swallowed, because failed runs still
 * consumed plan quota (ai_prompt_runs_monthly counts every run).
 *
 * Grain-style timestamps: created_at set manually at write time, no
 * updated_at — observations are immutable facts.
 */
class AiVisibilityRun extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'site_id', 'prompt_id', 'provider', 'model', 'status', 'response_hash',
        'tokens_used', 'cost_units', 'brand_mentioned', 'error',
        'checked_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at'      => 'datetime',
            'created_at'      => 'datetime',
            'cost_units'      => 'decimal:4',
            'brand_mentioned' => 'boolean',
        ];
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiVisibilityPrompt::class, 'prompt_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(AiVisibilityMention::class, 'run_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(AiVisibilityCitation::class, 'run_id');
    }
}
