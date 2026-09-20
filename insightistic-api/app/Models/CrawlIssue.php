<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Site-scoped issue with a cross-run lifecycle. fingerprint = sha1(code|target)
 * makes one row per (site, issue, affected URL): re-seeing it updates
 * last_seen_at, not seeing it in a later run resolves it, and an ignored
 * issue is never touched again.
 */
class CrawlIssue extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    protected $table = 'crawl_issues';

    protected $fillable = [
        'site_id', 'run_id', 'issue_code', 'severity', 'category', 'fingerprint',
        'target_url', 'page_id', 'evidence_json', 'first_seen_at', 'last_seen_at',
        'resolved_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at'  => 'datetime',
            'resolved_at'   => 'datetime',
            'evidence_json' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class, 'run_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(CrawlPage::class, 'page_id');
    }

    /** sha1 of "code|target" — the lifecycle key across runs. */
    public static function fingerprint(string $code, string $target): string
    {
        return sha1($code.'|'.$target);
    }
}
