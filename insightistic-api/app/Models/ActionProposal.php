<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Services\Actions\ActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An action that always requires an explicit human approval before executing (spec: Bridgistic Approved Actions, P9). See docs/BRIDGISTIC-APPROVED-ACTIONS.md. */
class ActionProposal extends Model
{
    use BelongsToOrganization;

    public const SOURCE_TYPES = ['manual', 'wpagentistic_investigation', 'anomaly', 'automation_rule'];

    public const STATUSES = ['pending', 'executed', 'failed', 'rejected'];

    protected $fillable = [
        'organization_id', 'site_id', 'source_type', 'source_id', 'action_type',
        'action_params_json', 'rationale', 'evidence_run_ids_json', 'status',
        'proposed_by_user_id', 'reviewed_by_user_id', 'reviewed_at', 'executed_at', 'result_json',
    ];

    protected function casts(): array
    {
        return [
            'action_params_json' => 'array',
            'evidence_run_ids_json' => 'array',
            'result_json' => 'array',
            'reviewed_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isValidActionType(): bool
    {
        return in_array($this->action_type, ActionType::ALL, true);
    }
}
