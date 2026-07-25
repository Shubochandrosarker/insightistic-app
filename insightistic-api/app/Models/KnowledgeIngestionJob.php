<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeIngestionJob extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'knowledge_base_id', 'knowledge_source_id', 'status',
        'source_count', 'chunk_count', 'token_count', 'duration_ms',
        'error_code', 'error_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }
}
