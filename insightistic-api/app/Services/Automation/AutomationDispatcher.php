<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Site;
use App\Services\Actions\ActionExecutor;
use Illuminate\Support\Facades\Log;

/**
 * Fires every active AutomationRule whose trigger matches an event (spec:
 * Automation Studio, P6). Three trigger types, each with its own match
 * rule:
 *
 *   - 'schedule'        : fires on its own cadence (trigger_config_json.frequency),
 *                          checked against the rule's own last_fired_at —
 *                          see shouldFireSchedule(). Driven by
 *                          `insightistic:automation-run-scheduled`, hourly.
 *   - 'alert_created'    : fires when AlertDetector actually creates a NEW
 *                          alert (not on every scan) — dispatched from
 *                          AlertDetector::raise(). $context carries
 *                          ['alert_type' => ..., 'alert_id' => ...];
 *                          trigger_config_json optionally filters by
 *                          alert_type (omit the key to match any type).
 *   - 'anomaly_detected' : fires when AnomalyScanner persists a NEW
 *                          anomaly — dispatched from AnomalyScanner::scan().
 *                          $context carries ['metric_id' => ..., 'anomaly_id' => ...];
 *                          trigger_config_json optionally filters by metric_id.
 *
 * Every firing — success or failure — is recorded as one AutomationRun,
 * mirroring EvidenceLedger's "never silently fail" convention. Action
 * execution itself always goes through ActionExecutor, the single place
 * P6 and P9 (ActionProposal) both actually run an action.
 */
class AutomationDispatcher
{
    public function __construct(private readonly ActionExecutor $executor) {}

    /**
     * @param  array<string, mixed>  $context  Trigger-specific data, matched against trigger_config_json and recorded verbatim on the AutomationRun.
     * @return AutomationRun[] Every rule actually fired this call.
     */
    public function dispatch(string $triggerType, Site $site, array $context = []): array
    {
        $rules = AutomationRule::query()
            ->where('site_id', $site->id)
            ->where('trigger_type', $triggerType)
            ->where('is_active', true)
            ->get();

        $fired = [];
        foreach ($rules as $rule) {
            if ($triggerType === 'schedule' ? ! $this->shouldFireSchedule($rule) : ! $this->matchesContext($rule, $context)) {
                continue;
            }

            // Doc 19 "rate limits per rule": skip when this rule already
            // fired within its cooldown window (default 60 min; override per
            // rule via trigger_config_json.cooldown_minutes). Manual
            // POST .../run calls fire() directly and is unaffected.
            $cooldownMinutes = max(1, (int) (($rule->trigger_config_json ?? [])['cooldown_minutes'] ?? 60));
            if ($rule->last_fired_at !== null && $rule->last_fired_at->diffInMinutes(now()) < $cooldownMinutes) {
                continue;
            }

            $fired[] = $this->fire($rule, $site, $context);
        }

        return $fired;
    }

    /** Every trigger_config_json key must be present in $context with an identical value. An empty config matches anything. */
    private function matchesContext(AutomationRule $rule, array $context): bool
    {
        foreach (($rule->trigger_config_json ?? []) as $key => $expected) {
            if (! array_key_exists($key, $context) || $context[$key] !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function shouldFireSchedule(AutomationRule $rule): bool
    {
        $frequency = ($rule->trigger_config_json ?? [])['frequency'] ?? 'daily';
        $minHours = match ($frequency) {
            'hourly' => 1,
            'daily' => 24,
            'weekly' => 24 * 7,
            default => 24,
        };

        return $rule->last_fired_at === null || $rule->last_fired_at->diffInHours(now()) >= $minHours;
    }

    public function fire(AutomationRule $rule, Site $site, array $context = []): AutomationRun
    {
        try {
            $result = $this->executor->execute(
                $rule->action_type,
                $rule->action_params_json ?? [],
                $site,
                $rule->created_by_user_id,
                $rule->webhook_secret,
            );
            $status = ($result['success'] ?? false) ? 'success' : 'failed';
        } catch (\Throwable $e) {
            Log::error('AutomationDispatcher: action execution threw an exception', ['rule_id' => $rule->id, 'message' => $e->getMessage()]);
            $result = ['success' => false, 'error' => 'exception'];
            $status = 'failed';
        }

        $rule->update(['last_fired_at' => now(), 'last_run_status' => $status]);

        return AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'organization_id' => $rule->organization_id,
            'site_id' => $site->id,
            'fired_at' => now(),
            'status' => $status,
            'trigger_context_json' => $context,
            'action_result_json' => $result,
            'error_message' => $status === 'failed' ? ($result['error'] ?? 'unknown') : null,
        ]);
    }
}
