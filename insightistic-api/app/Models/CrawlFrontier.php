<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-run URL queue. Status flow: pending → crawling (atomically claimed by a
 * worker) → done | failed | skipped (robots-disallowed). Run-scoped immutable
 * row: created_at only, no updated_at.
 */
class CrawlFrontier extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CRAWLING = 'crawling';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'crawl_frontier';

    public $timestamps = false;

    protected $fillable = [
        'run_id', 'site_id', 'url_hash', 'url', 'depth', 'status', 'error', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class, 'run_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
