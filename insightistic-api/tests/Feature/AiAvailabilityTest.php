<?php

use App\Models\AiMessage;
use App\Models\AiThread;
use App\Models\AutomationRule;
use App\Models\Site;
use App\Services\Ai\AiAvailability;

it('shows the invoice notice only when a paused tenant attempts Copilot', function () {
    [$user, $org] = makeOrgUser('paused-ai@example.com');
    $org->forceFill([
        'ai_settings_json' => ['paused' => true],
    ])->save();

    // Normal dashboard data access remains available.
    $this->actingAs($user)->getJson('/api/organizations/current')->assertOk();

    $threadId = $this->actingAs($user)->postJson('/api/copilot/threads', [])->assertCreated()->json('thread.id');
    $response = $this->actingAs($user)->postJson("/api/copilot/threads/{$threadId}/messages", [
        'content' => 'Research our latest performance.',
    ]);

    $response->assertStatus(402)
        ->assertJsonPath('code', AiAvailability::CODE)
        ->assertJsonPath('action', 'clear_pending_invoice')
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'pending invoice'));

    expect(AiMessage::withoutGlobalScope('organization')->where('ai_thread_id', $threadId)->count())->toBe(0);
});

it('pauses a configured site domain without pausing unrelated sites', function () {
    config()->set('insightistic.ai.paused_domains', ['brothertours.com']);
    [$user, $org] = makeOrgUser('paused-domain@example.com');
    $paused = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Brother Tours',
        'domain' => 'www.brothertours.com',
        'connection_status' => 'connected',
    ]);
    $other = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Other Site',
        'domain' => 'other.example.com',
        'connection_status' => 'connected',
    ]);

    $availability = app(AiAvailability::class);
    expect($availability->isPaused($org, $paused))->toBeTrue()
        ->and($availability->isPaused($org, $other))->toBeFalse();
});

it('does not fire scheduled automation rules for a paused tenant', function () {
    [, $org] = makeOrgUser('paused-schedule@example.com');
    $org->forceFill(['ai_settings_json' => ['paused' => true]])->save();
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Paused Site',
        'domain' => 'paused.example.com',
        'connection_status' => 'connected',
    ]);
    $rule = AutomationRule::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'name' => 'Paused schedule',
        'is_active' => true,
        'trigger_type' => 'schedule',
        'trigger_config_json' => ['frequency' => 'hourly'],
        'action_type' => 'send_notification_email',
        'action_params_json' => ['subject' => 'Paused', 'body' => 'Should not send'],
    ]);

    $this->artisan('insightistic:automation-run-scheduled')->assertSuccessful();

    expect($rule->fresh()->last_fired_at)->toBeNull()
        ->and($rule->runs()->count())->toBe(0);
});
