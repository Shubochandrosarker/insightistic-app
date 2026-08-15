<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Closes the loop on an executed ActionProposal — did the watched metric actually improve? (spec: Outcome Verification, P11). See docs/OUTCOME-VERIFICATION.md. */
class OutcomeVerification extends Model
{
    use BelongsToOrganization;

    public const OUTCOMES = ['improved', 'unchanged', 'worsened', 'no_metric_to_verify'];

    protected $fillable = [
        'organization_id', 'site_id', 'action_proposal_id', 'watched_metric_id',
        'baseline_evidence_run_id', 'followup_evidence_run_id', 'baseline_value', 'followup_value',
        'verified_at', 'outcome', 'delta_pct',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'delta_pct' => 'float',
            'baseline_value' => 'float',
            'followup_value' => 'float',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function actionProposal(): BelongsTo
    {
        return $this->belongsTo(ActionProposal::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
