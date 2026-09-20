<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page observation within one crawl run. Run-scoped immutable fact:
 * created_at only, no updated_at.
 */
class CrawlPage extends Model
{
    protected $table = 'crawl_pages';

    public $timestamps = false;

    protected $fillable = [
        'run_id', 'site_id', 'url_hash', 'url', 'status_code', 'redirect_to',
        'indexable', 'robots_noindex', 'canonical_url', 'title', 'meta_description',
        'h1_count', 'word_count', 'content_hash', 'schema_types_json',
        'response_ms', 'size_bytes', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at'       => 'datetime',
            'schema_types_json' => 'array',
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
