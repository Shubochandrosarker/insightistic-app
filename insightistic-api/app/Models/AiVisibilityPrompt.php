<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stable AI-visibility prompt monitored over time (Phase 11, doc 14).
 * Active=false is the soft lifecycle: rows survive so the run history they
 * anchored stays meaningful, and a re-activation resumes the same lineage.
 * last_run_at drives the daily due-pick in RunAiVisibilityJob.
 */
class AiVisibilityPrompt extends Model
{
    protected $fillable = [
        'site_id', 'prompt', 'category', 'active', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'active'      => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiVisibilityRun::class, 'prompt_id');
    }
}
