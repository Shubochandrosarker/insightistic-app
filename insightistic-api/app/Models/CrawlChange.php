<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (page, field) diff between two consecutive completed crawl runs of a
 * site (Phase 8 change monitoring, doc 12 §Change monitoring).
 *
 * Written once at run finalize by ChangeDetection; impressions is the GSC
 * 28-day snapshot taken when the change was detected, not a live figure.
 */
class CrawlChange extends Model
{
    public const FIELD_TITLE = 'title';
    public const FIELD_META_DESCRIPTION = 'meta_description';
    public const FIELD_CANONICAL = 'canonical';
    public const FIELD_INDEXABILITY = 'indexability';
    public const FIELD_STATUS_CODE = 'status_code';
    public const FIELD_H1_COUNT = 'h1_count';
    public const FIELD_CONTENT = 'content';

    public const FIELDS = [
        self::FIELD_TITLE,
        self::FIELD_META_DESCRIPTION,
        self::FIELD_CANONICAL,
        self::FIELD_INDEXABILITY,
        self::FIELD_STATUS_CODE,
        self::FIELD_H1_COUNT,
        self::FIELD_CONTENT,
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'site_id', 'run_id', 'page_hash', 'url', 'field', 'severity',
        'old_value', 'new_value', 'impressions',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class, 'run_id');
    }
}
