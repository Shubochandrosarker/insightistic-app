<?php

use App\Models\ActionProposal;
use App\Models\AiMessage;
use App\Models\AiThread;
use App\Models\Alert;
use App\Models\Anomaly;
use App\Models\AutomationRule;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Organization;
use App\Models\ReportSchedule;
use App\Models\Site;
use App\Models\User;
use App\Services\Ai\AiProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regression tests for the "deep audit" fixes: mutating P6/P7/P9/legacy
 * endpoints must not be reachable by a client_viewer (read-only stakeholder
 * access), the WordPress-plugin AI endpoint must honor allow_external_ai,
 * and Copilot feedback must be scoped to the requesting user's own threads.
 */
function auditFixesSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Audit Fixes Shop',
        'domain' => 'audit-fixes-'.uniqid().'.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

/** Local copy of ConnectorHmacTest's signing helper, uniquely named to avoid
 * a "cannot redeclare" collision when the full suite loads both files. */
function auditFixesHmacHeaders(string $keyId, string $secret, string $method, string $path, string $body): array
{
    $timestamp = (string) time();
    $nonce = Str::uuid()->toString();
    $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);

    return [
        'X-INS-Key-Id' => $keyId,
        'X-INS-Timestamp' => $timestamp,
        'X-INS-Nonce' => $nonce,
        'X-INS-Signature' => hash_hmac('sha256', $canonical, $secret),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}

function attachClientViewer(Organization $org, Site $site, string $email): User
{
    $viewer = User::create([
        'name' => 'Viewer', 'email' => $email,
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($viewer->id, ['role' => 'client_viewer']);
    DB::table('site_user_access')->insert([
        'site_id' => $site->id, 'user_id' => $viewer->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $viewer;
}

it('keeps automation-rule mutations away from client viewers, but still lets them read', function () {
    [$owner, $org] = makeOrgUser('audit-auto-owner@example.com');
    $site = auditFixesSite($org);
    $viewer = attachClientViewer($org, $site, 'audit-auto-viewer@example.com');

    $rule = AutomationRule::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'created_by_user_id' => $owner->id,
        'name' => 'Digest', 'is_active' => true,
        'trigger_type' => 'schedule', 'trigger_config_json' => ['frequency' => 'daily'],
        'action_type' => 'send_webhook', 'action_params_json' => ['url' => 'https://example.com/hook', 'payload' => []],
    ]);

    $this->actingAs($viewer)->getJson("/api/sites/{$site->id}/automation-rules")->assertOk();
    app('auth')->forgetGuards();

    $this->actingAs($viewer)
        ->postJson("/api/sites/{$site->id}/automation-rules", [
            'name' => 'Attacker rule', 'trigger_type' => 'schedule', 'trigger_config' => ['frequency' => 'daily'],
            'action_type' => 'send_webhook', 'action_params' => ['url' => 'https://evil.example.com', 'payload' => []],
        ])->assertForbidden();
    app('auth')->forgetGuards();

    $this->actingAs($viewer)->postJson("/api/sites/{$site->id}/automation-rules/{$rule->id}/run")->assertForbidden();
    app('auth')->forgetGuards();

    $this->actingAs($viewer)->deleteJson("/api/sites/{$site->id}/automation-rules/{$rule->id}")->assertForbidden();
});

it('keeps anomaly acknowledgement away from client viewers', function () {
    [$owner, $org] = makeOrgUser('audit-anom-owner@example.com');
    $site = auditFixesSite($org);
    $viewer = attachClientViewer($org, $site, 'audit-anom-viewer@example.com');

    $anomaly = Anomaly::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'metric_id' => 'net_revenue',
        'date' => now()->toDateString(), 'value' => 10.0, 'baseline_median' => 100.0, 'mad' => 5.0,
        'score' => 18.0, 'direction' => 'down', 'severity' => 'high', 'is_acknowledged' => false,
        'detected_at' => now(),
    ]);

    $this->actingAs($viewer)->getJson("/api/sites/{$site->id}/anomalies")->assertOk();
    app('auth')->forgetGuards();

    $this->actingAs($viewer)
        ->postJson("/api/sites/{$site->id}/anomalies/{$anomaly->id}/acknowledge")
        ->assertForbidden();
    expect($anomaly->fresh()->is_acknowledged)->toBeFalse();
});

it('keeps action-proposal approve/reject away from client viewers', function () {
    [$owner, $org] = makeOrgUser('audit-prop-owner@example.com');
    $site = auditFixesSite($org);
    $viewer = attachClientViewer($org, $site, 'audit-prop-viewer@example.com');

    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);
    $proposal = ActionProposal::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'proposed_by_user_id' => $owner->id,
        'action_type' => 'resolve_alert', 'action_params_json' => ['alert_id' => $alert->id],
        'rationale' => 'Back to normal.', 'evidence_run_ids_json' => [], 'status' => 'pending',
    ]);

    $this->actingAs($viewer)
        ->postJson("/api/sites/{$site->id}/action-proposals/{$proposal->id}/approve")
        ->assertForbidden();
    app('auth')->forgetGuards();

    $this->actingAs($viewer)
        ->postJson("/api/sites/{$site->id}/action-proposals/{$proposal->id}/reject")
        ->assertForbidden();

    expect($proposal->fresh()->status)->toBe('pending');
    expect($alert->fresh()->is_resolved)->toBeFalse();
});

it('keeps alert resolve/reopen away from client viewers', function () {
    [$owner, $org] = makeOrgUser('audit-alert-owner@example.com');
    $site = auditFixesSite($org);
    $viewer = attachClientViewer($org, $site, 'audit-alert-viewer@example.com');

    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);

    $this->actingAs($viewer)
        ->postJson("/api/sites/{$site->id}/alerts/{$alert->id}/resolve")
        ->assertForbidden();
    expect($alert->fresh()->is_resolved)->toBeFalse();
});

it('keeps report-schedule mutations owner/admin only', function () {
    [$owner, $org] = makeOrgUser('audit-rs-owner@example.com');
    $site = auditFixesSite($org);
    $analyst = User::create([
        'name' => 'Analyst', 'email' => 'audit-rs-analyst@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($analyst->id, ['role' => 'analyst']);
    DB::table('site_user_access')->insert([
        'site_id' => $site->id, 'user_id' => $analyst->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/report-schedules", [
            'frequency' => 'weekly', 'recipients' => ['a@example.com'],
        ])->assertForbidden();
    app('auth')->forgetGuards();

    $schedule = ReportSchedule::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'frequency' => 'weekly', 'recipients' => 'a@example.com',
        'send_day' => 1, 'send_time' => '09:00', 'timezone' => 'UTC', 'is_active' => true,
    ]);

    $this->actingAs($analyst)
        ->deleteJson("/api/sites/{$site->id}/report-schedules/{$schedule->id}")
        ->assertForbidden();
});

it('refuses the plugin AI endpoint when the org has opted out of external AI, instead of ignoring consent', function () {
    [, $org] = makeOrgUser('audit-plugin-ai@example.com');
    $org->forceFill(['allow_external_ai' => false])->save();

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'Plugin AI Shop',
        'domain' => 'plugin-ai-'.uniqid().'.example.com',
        'connector_key_id' => 'ik_'.Str::random(24),
    ]);
    $secret = 'sk_'.Str::random(48);
    $site->forceFill(['connector_secret' => $secret])->save();

    $license = License::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'plan_id' => $org->plan_id,
        'license_key_hash' => hash('sha256', Str::random(32)), 'license_key_last4' => '1234',
        'status' => 'active', 'activation_limit' => 5,
    ]);
    LicenseActivation::create([
        'license_id' => $license->id, 'site_id' => $site->id,
        'site_url' => 'https://plugin-ai.example.com', 'normalized_site_url' => 'plugin-ai.example.com',
        'site_hash' => hash('sha256', 'plugin-ai.example.com'), 'status' => 'active',
        'activated_at' => now(),
    ]);

    // A provider that would prove the consent check was bypassed if reached.
    $spy = new class implements AiProvider
    {
        public bool $called = false;

        public function model(): string
        {
            return 'spy-provider';
        }

        public function generate(string $system, array $context, ?int $identityId = null): ?array
        {
            $this->called = true;

            return ['overall_score' => 99];
        }
    };
    $this->app->instance(AiProvider::class, $spy);

    $body = json_encode([
        'model' => 'ollama-balanced', 'system_prompt' => 'x', 'prompt' => 'business data here',
    ]);
    $headers = auditFixesHmacHeaders($site->connector_key_id, $secret, 'POST', '/api/plugin/ai-insights', $body);

    $this->call('POST', '/api/plugin/ai-insights', [], [], [], $this->transformHeadersToServerVars($headers), $body)
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_provider_unavailable');

    expect($spy->called)->toBeFalse();
});

it('keeps Copilot feedback scoped to the requesting user\'s own threads', function () {
    [$userA, $org] = makeOrgUser('audit-copilot-a@example.com');
    $userB = User::create([
        'name' => 'User B', 'email' => 'audit-copilot-b@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($userB->id, ['role' => 'owner']);

    $thread = AiThread::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'user_id' => $userA->id, 'title' => 'Thread',
    ]);
    $message = AiMessage::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'ai_thread_id' => $thread->id, 'role' => 'assistant', 'content' => 'reply', 'status' => 'completed',
    ]);

    $this->actingAs($userB)
        ->postJson("/api/copilot/messages/{$message->id}/feedback", ['rating' => 1])
        ->assertNotFound();
    app('auth')->forgetGuards();

    $this->actingAs($userA)
        ->postJson("/api/copilot/messages/{$message->id}/feedback", ['rating' => 1])
        ->assertOk();
});
