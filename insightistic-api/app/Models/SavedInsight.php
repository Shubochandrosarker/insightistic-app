<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A named, reusable SemanticQueryPlan a user can re-run on demand (spec: Saved Insights, P5). See docs/SAVED-INSIGHTS.md. */
class SavedInsight extends Model
{
    use BelongsToOrganization;

    public const SCOPES = ['organization', 'private'];

    protected $fillable = [
        'organization_id', 'site_id', 'owner_user_id', 'name', 'description',
        'semantic_plan_json', 'metric_versions_json', 'chart_spec_json',
        'scope', 'thresholds_json', 'version', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'semantic_plan_json' => 'array',
            'metric_versions_json' => 'array',
            'chart_spec_json' => 'array',
            'thresholds_json' => 'array',
            'version' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Visible to $userId — either shared org-wide, or private and owned by them. */
    public function visibleTo(int $userId): bool
    {
        return $this->scope !== 'private' || $this->owner_user_id === $userId;
    }
}
