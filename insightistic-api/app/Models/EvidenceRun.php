<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceRun extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'site_id', 'requested_by_user_id', 'source', 'run_type',
        'validator_version', 'semantic_plan_json', 'metric_versions_json', 'query_hash',
        'row_count', 'duration_ms', 'read_only_identity_active', 'freshness_json',
        'status', 'error_code', 'error_message', 'ai_used', 'ai_model',
    ];

    protected function casts(): array
    {
        return [
            'semantic_plan_json' => 'array',
            'metric_versions_json' => 'array',
            'freshness_json' => 'array',
            'read_only_identity_active' => 'boolean',
            'duration_ms' => 'float',
            'ai_used' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }
}
