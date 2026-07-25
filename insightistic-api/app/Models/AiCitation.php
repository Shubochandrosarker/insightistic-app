<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCitation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'ai_message_id', 'knowledge_chunk_id', 'knowledge_source_id',
        'source_type', 'title', 'url', 'snippet', 'score', 'data_date', 'last_indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'data_date'       => 'date',
            'last_indexed_at' => 'datetime',
        ];
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'knowledge_chunk_id');
    }
}
