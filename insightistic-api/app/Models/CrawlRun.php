<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlRun extends Model
{
    // Explicit table (credit_ledger precedent — never trust pluralization).
    protected $table = 'crawl_runs';

    protected $fillable = [
        'site_id', 'status', 'trigger', 'started_at', 'completed_at',
        'max_pages', 'pages_discovered', 'pages_crawled', 'issues_found',
        'config_json', 'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'config_json'  => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(CrawlPage::class, 'run_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(CrawlLink::class, 'run_id');
    }

    public function frontier(): HasMany
    {
        return $this->hasMany(CrawlFrontier::class, 'run_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(CrawlIssue::class, 'run_id');
    }

    /** Runtime crawl config (max_depth, delay_ms, respect_robots...). */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config_json[$key] ?? $default;
    }
}
