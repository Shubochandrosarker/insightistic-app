<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-site competitor definition for AI visibility matching (Phase 11,
 * doc 14 §Competitors). Matching uses the canonical name plus every alias
 * (product names, domains, common abbreviations) — normalized alias
 * matching happens at extraction time (MentionExtractor), so renaming or
 * deleting a competitor never rewrites stored mentions.
 */
class AiVisibilityCompetitor extends Model
{
    protected $fillable = [
        'site_id', 'name', 'aliases_json',
    ];

    protected function casts(): array
    {
        return [
            'aliases_json' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
