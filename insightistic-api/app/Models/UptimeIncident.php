<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One outage lifecycle (Phase 12, doc 16):
 *
 *   healthy -> suspected -> confirmed incident -> recovered
 *
 * started_at  set by the FIRST failed probe. The incident is SUSPECTED
 *             while confirmed_at is null — doc 16 forbids alerting on a
 *             single failed probe (transient blips must not page anyone).
 * confirmed_at set ONCE by the SECOND consecutive failed probe when it
 *             lands at least one interval after started_at — this is the
 *             confirmation rule, and the only moment an alert is raised.
 * recovered_at set by the first successful probe afterwards. Rows are
 *             never deleted — recovered incidents stay forever so the
 *             incident history can identify outage dates.
 */
class UptimeIncident extends Model
{
    public const STATUS_SUSPECTED = 'suspected';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_RECOVERED = 'recovered';

    protected $fillable = [
        'monitor_id', 'site_id', 'started_at', 'confirmed_at', 'recovered_at', 'cause', 'status_code',
    ];

    protected function casts(): array
    {
        return [
            'started_at'   => 'datetime',
            'confirmed_at' => 'datetime',
            'recovered_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'monitor_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Derived lifecycle status from the two milestone timestamps. */
    public function lifecycleStatus(): string
    {
        if ($this->recovered_at !== null) {
            return self::STATUS_RECOVERED;
        }

        return $this->confirmed_at !== null ? self::STATUS_CONFIRMED : self::STATUS_SUSPECTED;
    }

    /** Outage duration in seconds; still-open incidents count up to now. */
    public function durationSeconds(): int
    {
        $end = $this->recovered_at ?? Carbon::now();

        return max(0, (int) $this->started_at->diffInSeconds($end));
    }
}
