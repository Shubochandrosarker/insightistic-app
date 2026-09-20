<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One probe observation (Phase 12, doc 16) — append-only, immutable.
 * status 'up' means status_code === expected_status; a network failure
 * (timeout, DNS, TLS) is a 'down' row with status_code null and the
 * exception message in error.
 */
class UptimeCheck extends Model
{
    public const UP = 'up';

    public const DOWN = 'down';

    /** Checks are observations, never edited after the write. */
    public $timestamps = false;

    protected $fillable = [
        'monitor_id', 'site_id', 'status', 'status_code', 'response_ms', 'error', 'checked_at',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'monitor_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
