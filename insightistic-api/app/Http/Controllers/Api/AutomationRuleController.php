<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\Site;
use App\Services\Actions\ActionType;
use App\Services\Automation\AutomationCatalog;
use App\Services\Automation\AutomationDispatcher;
use Illuminate\Http\Request;

/**
 * CRUD + manual "run now" for Automation Studio rules (spec: P6). Every
 * write validates trigger_type/action_type against their respective
 * allowlists (App\Models\AutomationRule::TRIGGER_TYPES,
 * App\Services\Actions\ActionType::ALL) — a rule can never be saved with a
 * trigger or action this system doesn't actually know how to run.
 */
class AutomationRuleController extends Controller
{
    public function __construct(private readonly AutomationDispatcher $dispatcher) {}

    public function index(Request $request)
    {
        $site = $this->site($request);
        $rules = AutomationRule::query()->where('site_id', $site->id)->orderByDesc('created_at')->get();

        return response()->json(['automation_rules' => $rules]);
    }

    public function store(Request $request)
    {
        $site = $this->site($request);
        $userId = $request->user()?->id;

        $error = $this->validatePayload($request);
        if ($error) {
            return $error;
        }

        $actionType = $request->input('action_type');
        $webhookSecret = null;
        if ($actionType === ActionType::SEND_WEBHOOK) {
            $webhookSecret = $request->input('webhook_secret') ? (string) $request->input('webhook_secret') : null;
        }

        $rule = AutomationRule::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'created_by_user_id' => $userId,
            'name' => trim((string) $request->input('name')),
            'is_active' => (bool) $request->input('is_active', true),
            'trigger_type' => $request->input('trigger_type'),
            'trigger_config_json' => is_array($request->input('trigger_config')) ? $request->input('trigger_config') : [],
            'action_type' => $actionType,
            'action_params_json' => is_array($request->input('action_params')) ? $request->input('action_params') : [],
            'webhook_secret' => $webhookSecret,
        ]);

        return response()->json(['automation_rule' => $rule], 201);
    }

    public function show(Request $request, int $siteId, int $automationRule)
    {
        $rule = $this->find($request, $automationRule);

        return response()->json(['automation_rule' => $rule]);
    }

    public function update(Request $request, int $siteId, int $automationRule)
    {
        $rule = $this->find($request, $automationRule);

        if ($request->has('name') || $request->has('trigger_type') || $request->has('action_type')) {
            $error = $this->validatePayload($request, partial: true, existing: $rule);
            if ($error) {
                return $error;
            }
        }

        $changes = array_filter([
            'name' => $request->has('name') ? trim((string) $request->input('name')) : null,
            'is_active' => $request->has('is_active') ? (bool) $request->input('is_active') : null,
            'trigger_type' => $request->input('trigger_type'),
            'trigger_config_json' => is_array($request->input('trigger_config')) ? $request->input('trigger_config') : null,
            'action_type' => $request->input('action_type'),
            'action_params_json' => is_array($request->input('action_params')) ? $request->input('action_params') : null,
        ], fn ($v) => $v !== null);

        if ($request->has('webhook_secret')) {
            $changes['webhook_secret'] = $request->input('webhook_secret') ? (string) $request->input('webhook_secret') : null;
        }

        if ($changes !== []) {
            $rule->update($changes);
        }

        return response()->json(['automation_rule' => $rule->fresh()]);
    }

    public function destroy(Request $request, int $siteId, int $automationRule)
    {
        $rule = $this->find($request, $automationRule);
        $rule->delete();

        return response()->json(['deleted' => true]);
    }

    /** POST .../run — fires the rule immediately regardless of its trigger, for testing. Recorded exactly like a real trigger firing. */
    public function run(Request $request, int $siteId, int $automationRule)
    {
        $rule = $this->find($request, $automationRule);
        $site = $this->site($request);

        $run = $this->dispatcher->fire($rule, $site, ['manual' => true]);

        return response()->json(['automation_run' => $run]);
    }

    /** GET .../runs — the audit trail for one rule, newest first. */
    public function runs(Request $request, int $siteId, int $automationRule)
    {
        $rule = $this->find($request, $automationRule);
        $runs = $rule->runs()->orderByDesc('fired_at')->limit(100)->get();

        return response()->json(['automation_runs' => $runs]);
    }

    /** GET .../catalog — what the rule builder can offer: triggers AutomationDispatcher really implements and actions ActionExecutor really runs (AutomationCatalog::catalog()). Read-only, so any site-access role may load it — same policy as index/show/runs above. */
    public function catalog(Request $request)
    {
        $this->site($request); // site already resolved + org-scoped by the site.access middleware

        return response()->json(AutomationCatalog::catalog());
    }

    private function validatePayload(Request $request, bool $partial = false, ?AutomationRule $existing = null): mixed
    {
        $name = $request->input('name', $existing?->name);
        if (! $partial && trim((string) $name) === '') {
            return response()->json(['message' => 'name is required.', 'code' => 'invalid_name'], 422);
        }

        $triggerType = $request->input('trigger_type', $existing?->trigger_type);
        if (! in_array($triggerType, AutomationRule::TRIGGER_TYPES, true)) {
            return response()->json(['message' => 'trigger_type must be one of: '.implode(', ', AutomationRule::TRIGGER_TYPES).'.', 'code' => 'invalid_trigger_type'], 422);
        }

        $actionType = $request->input('action_type', $existing?->action_type);
        if (! in_array($actionType, ActionType::ALL, true)) {
            return response()->json(['message' => 'action_type must be one of: '.implode(', ', ActionType::ALL).'.', 'code' => 'invalid_action_type'], 422);
        }

        if ($actionType === ActionType::SEND_WEBHOOK) {
            $params = $request->input('action_params');
            $url = is_array($params) ? ($params['url'] ?? null) : null;
            if (! $url && ! ($existing && ($existing->action_params_json['url'] ?? null))) {
                return response()->json(['message' => 'action_params.url is required for send_webhook.', 'code' => 'invalid_action_params'], 422);
            }
        }

        return null;
    }

    private function find(Request $request, int $id): AutomationRule
    {
        $site = $this->site($request);
        $rule = AutomationRule::query()->where('site_id', $site->id)->find($id);

        abort_if(! $rule, 404, 'Automation rule not found.');

        return $rule;
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
