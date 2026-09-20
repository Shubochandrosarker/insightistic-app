<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One URL in one IndexNow submission batch — an immutable audit log
 * (docs/roadmap-2026/11 §IndexNow/Metrics: submissions attempted, accepted/
 * failed, error reason). created_at only; rows are never updated in place
 * except to resolve pending -> accepted/failed when the provider answers.
 */
class IndexNowSubmission extends Model
{
    protected $table = 'indexnow_submissions';

    public const UPDATED_AT = null; // immutable log grain: created_at only

    public const SOURCE_MANUAL  = 'manual';
    public const SOURCE_PLUGIN  = 'plugin';
    public const SOURCE_AUTO    = 'auto';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_DUPLICATE = 'skipped_duplicate';

    protected $fillable = [
        'site_id', 'url', 'url_hash', 'source', 'batch_id',
        'status', 'http_status', 'retryable', 'error', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'retryable'   => 'boolean',
            'created_at'  => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
