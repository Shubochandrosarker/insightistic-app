<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One indexed retrieval unit. `embedding` (pgvector) and `search_vector`
 * (tsvector) are database-managed columns — the model never mass-assigns
 * them; embeddings are written via KnowledgeIngestor's parameterized update.
 */
class KnowledgeChunk extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'site_id', 'knowledge_source_id', 'chunk_index', 'content',
        'content_hash', 'token_count', 'metadata_json', 'embedding_model',
    ];

    protected $hidden = ['embedding', 'search_vector'];

    protected function casts(): array
    {
        return ['metadata_json' => 'array'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }
}
