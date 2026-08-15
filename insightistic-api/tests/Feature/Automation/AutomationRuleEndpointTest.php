<?php

use App\Models\AutomationRule;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function ruleEndpointSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Rule Shop',
        'domain' => 'rule-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

it('creates a schedule-triggered rule with a send_webhook action', function () {
    [$user, $org] = makeOrgUser('rule-create@example.com');
    $site = ruleEndpointSite($org);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/automation-rules", [
            'name' => 'Daily digest',
            'trigger_type' => 'schedule',
            'trigger_config' => ['frequency' => 'daily'],
            'action_type' => 'send_webhook',
            'action_params' => ['url' => 'https://example.com/hook', 'payload' => ['kind' => 'digest']],
            'webhook_secret' => 'top-secret',
        ])
        ->assertCreated()
        ->json('automation_rule');

    expect($body['name'])->toBe('Daily digest')
        ->and($body)->not->toHaveKey('webhook_secret'); // hidden from API responses
});

it('rejects an unknown trigger_type or action_type', function () {
    [$user, $org] = makeOrgUser('rule-invalid@example.com');
    $site = ruleEndpointSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/automation-rules", [
            'name' => 'X', 'trigger_type' => 'not_a_trigger', 'action_type' => 'send_webhook',
            'action_params' => ['url' => 'https://example.com/hook'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_trigger_type');

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/automation-rules", [
            'name' => 'X', 'trigger_type' => 'schedule', 'action_type' => 'not_an_action',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_action_type');
});

it('requires a url for a send_webhook action', function () {
    [$user, $org] = makeOrgUser('rule-webhook-no-url@example.com');
    $site = ruleEndpointSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/automation-rules", [
            'name' => 'X', 'trigger_type' => 'schedule', 'action_type' => 'send_webhook',
            'action_params' => [],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_action_params');
});

it('lists, updates, and deletes a rule', function () {
    [$user, $org] = makeOrgUser('rule-crud@example.com');
    $site = ruleEndpointSite($org);
    $rule = AutomationRule::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'name' => 'Original',
        'is_active' => true, 'trigger_type' => 'schedule', 'trigger_config_json' => ['frequency' => 'daily'],
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
    ]);

    $this->actingAs($user)->getJson("/api/sites/{$site->id}/automation-rules")
        ->assertOk()->assertJsonCount(1, 'automation_rules');

    $this->actingAs($user)
        ->patchJson("/api/sites/{$site->id}/automation-rules/{$rule->id}", ['name' => 'Renamed', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('automation_rule.name', 'Renamed')
        ->assertJsonPath('automation_rule.is_active', false);

    $this->actingAs($user)
        ->deleteJson("/api/sites/{$site->id}/automation-rules/{$rule->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(AutomationRule::find($rule->id))->toBeNull();
});

it('manually runs a rule and records the run in its history', function () {
    Mail::fake();
    [$user, $org] = makeOrgUser('rule-run@example.com');
    $site = ruleEndpointSite($org);
    $rule = AutomationRule::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'name' => 'Manual',
        'is_active' => true, 'trigger_type' => 'schedule', 'trigger_config_json' => [],
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
    ]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/automation-rules/{$rule->id}/run")
        ->assertOk()
        ->assertJsonPath('automation_run.status', 'success');

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/automation-rules/{$rule->id}/runs")
        ->assertOk()
        ->assertJsonCount(1, 'automation_runs');
});

it('never lets a rule or its runs leak across organizations', function () {
    [$userA, $orgA] = makeOrgUser('rule-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('rule-iso-b@example.com');
    $siteA = ruleEndpointSite($orgA);
    $rule = AutomationRule::create([
        'organization_id' => $orgA->id, 'site_id' => $siteA->id, 'name' => 'A only',
        'is_active' => true, 'trigger_type' => 'schedule', 'trigger_config_json' => [],
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
    ]);

    $this->actingAs($userB)->getJson("/api/sites/{$siteA->id}/automation-rules/{$rule->id}")->assertNotFound();
    $this->actingAs($userB)->postJson("/api/sites/{$siteA->id}/automation-rules/{$rule->id}/run")->assertNotFound();
    $this->actingAs($userB)->deleteJson("/api/sites/{$siteA->id}/automation-rules/{$rule->id}")->assertNotFound();
});
