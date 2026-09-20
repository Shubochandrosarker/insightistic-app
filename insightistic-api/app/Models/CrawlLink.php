<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link observed on one crawled page within one crawl run.
 * Run-scoped immutable fact: created_at only, no updated_at.
 */
class CrawlLink extends Model
{
    protected $table = 'crawl_links';

    public $timestamps = false;

    protected $fillable = [
        'run_id', 'site_id', 'source_page_id', 'target_url_hash', 'target_url',
        'is_internal', 'status_code', 'is_nofollow', 'anchor', 'created_at',
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

    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(CrawlPage::class, 'source_page_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
