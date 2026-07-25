<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeSource extends Model
{
    use BelongsToOrganization;

    public const TYPES = ['file', 'url', 'sitemap', 'wordpress_post', 'product', 'manual', 'integration'];

    public const STATUSES = ['pending', 'processing', 'indexed', 'failed', 'disabled'];

    protected $fillable = [
        'organization_id', 'site_id', 'knowledge_base_id', 'type', 'title', 'source_url',
        'storage_path', 'mime_type', 'checksum', 'size_bytes', 'status', 'error_code',
        'error_message', 'metadata_json', 'chunk_count', 'last_indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json'   => 'array',
            'last_indexed_at' => 'datetime',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function ingestionJobs(): HasMany
    {
        return $this->hasMany(KnowledgeIngestionJob::class);
    }
}
