<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Services\Actions\ActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A trigger → action rule an org admin configures once (spec: Automation Studio, P6). See docs/AUTOMATION-STUDIO.md. */
class AutomationRule extends Model
{
    use BelongsToOrganization;

    public const TRIGGER_TYPES = ['schedule', 'alert_created', 'anomaly_detected'];

    protected $fillable = [
        'organization_id', 'site_id', 'created_by_user_id', 'name', 'is_active',
        'trigger_type', 'trigger_config_json', 'action_type', 'action_params_json',
        'webhook_secret', 'last_fired_at', 'last_run_status',
    ];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trigger_config_json' => 'array',
            'action_params_json' => 'array',
            'webhook_secret' => 'encrypted',
            'last_fired_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function isValidActionType(): bool
    {
        return in_array($this->action_type, ActionType::ALL, true);
    }

    public function isValidTriggerType(): bool
    {
        return in_array($this->trigger_type, self::TRIGGER_TYPES, true);
    }
}
