<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uptime monitor per site (Phase 12, doc 16). The site relationship is
 * the tenant boundary — this table carries no organization_id of its own,
 * so every query that leaves the site context (scheduler, queue) must join
 * or eager-load the site and check archived/deletion state itself.
 *
 * Lifecycle status is derived, not stored: an incident is
 *   SUSPECTED  when confirmed_at is null (first failed probe),
 *   CONFIRMED  when confirmed_at is set,
 *   RECOVERED  when recovered_at is set (history stays forever).
 */
class UptimeMonitor extends Model
{
    /** HTTP methods a monitor may use. MVP keeps this to the cheap ones. */
    public const METHODS = ['GET', 'HEAD'];

    protected $fillable = [
        'site_id', 'url', 'method', 'expected_status', 'interval_minutes', 'active',
        'last_checked_at', 'ssl_expires_at', 'ssl_checked_at',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'ssl_expires_at'  => 'date',
            'ssl_checked_at'  => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class, 'monitor_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(UptimeIncident::class, 'monitor_id');
    }

    /** The currently open (not yet recovered) incident, if any. */
    public function openIncident(): ?UptimeIncident
    {
        return $this->incidents()->whereNull('recovered_at')->orderByDesc('id')->first();
    }

    /** The most recent check row, if any. */
    public function lastCheck(): ?UptimeCheck
    {
        return $this->checks()->orderByDesc('checked_at')->orderByDesc('id')->first();
    }

    /** Resolve the probe target: explicit url, else the site's own domain. */
    public function probeUrl(): string
    {
        if ($this->url !== null && $this->url !== '') {
            return $this->url;
        }

        $domain = preg_replace('#^https?://#', '', (string) $this->site?->domain);

        return 'https://'.rtrim((string) $domain, '/');
    }

    /**
     * Whether this monitor is due for a probe: never checked, or the last
     * check is older than the monitor's own interval. The scheduler runs
     * every 5 minutes and lets each monitor decide its own cadence.
     */
    public function isDue(): bool
    {
        return $this->last_checked_at === null
            || $this->last_checked_at->lt(Carbon::now()->subMinutes($this->interval_minutes));
    }

    /**
     * Availability over the last 30 days as a 0-100 percentage with one
     * decimal, or null when there is no check history yet (an honest
     * "no data" beats a misleading 100%).
     */
    public function availabilityPct(): ?float
    {
        $counts = $this->checks()
            ->where('checked_at', '>=', Carbon::now()->subDays(30))
            ->selectRaw("count(*) as total, sum(case when status = 'up' then 1 else 0 end) as ups")
            ->first();

        if ($counts === null || (int) $counts->total === 0) {
            return null;
        }

        return round(((int) $counts->ups / (int) $counts->total) * 100, 1);
    }
}
