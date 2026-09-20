<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cursor + status for one historical Search Console backfill (site ×
 * dimension). `covered_from`/`covered_to` are the high-water marks a retried
 * job resumes from (doc 08), so a crash or provider error never re-pulls
 * dates that already landed.
 */
class SeoBackfillState extends Model
{
    /** Migration creates the singular form; Laravel would pluralize to *_states. */
    protected $table = 'seo_backfill_state';

    /** Backfillable daily-grain dimensions (mirrors GscClient::fetchDailyWindow). */
    public const DIMENSIONS = ['queries', 'pages', 'query_page'];

    protected $fillable = [
        'site_id', 'dimension', 'covered_from', 'covered_to',
        'status', 'attempts', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'covered_from' => 'date',
            'covered_to'   => 'date',
            'attempts'     => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
