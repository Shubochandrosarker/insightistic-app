<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One audit-trail row per AutomationRule firing — success or failure, never silent (spec: Automation Studio, P6). */
class AutomationRun extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'automation_rule_id', 'organization_id', 'site_id', 'fired_at', 'status',
        'trigger_context_json', 'action_result_json', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'fired_at' => 'datetime',
            'trigger_context_json' => 'array',
            'action_result_json' => 'array',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }
}
