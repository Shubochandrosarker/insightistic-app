<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One http(s) URL cited in one AI-visibility answer (Phase 11, doc 14
 * §Citation intelligence). is_owned is resolved at write time by comparing
 * the citation's domain with the site's domain (www-insensitive) — doc 14:
 * "your brand is mentioned in X% of answers, but your own domain is cited
 * in only Y%" comes straight from these rows.
 *
 * Grain-style timestamps: created_at set manually at write time.
 */
class AiVisibilityCitation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'site_id', 'url', 'domain', 'is_owned', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_owned'   => 'boolean',
            'created_at' => 'datetime',
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
