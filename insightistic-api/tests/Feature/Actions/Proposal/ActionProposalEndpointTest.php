<?php

use App\Models\ActionProposal;
use App\Models\Alert;
use App\Models\Organization;
use App\Models\Site;

function proposalEndpointSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Proposal Endpoint Shop',
        'domain' => 'proposal-ep-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

it('creates a proposal via the API without executing it', function () {
    [$user, $org] = makeOrgUser('proposal-ep-create@example.com');
    $site = proposalEndpointSite($org);
    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/action-proposals", [
            'action_type' => 'resolve_alert',
            'action_params' => ['alert_id' => $alert->id],
            'rationale' => 'Revenue is back to normal.',
        ])
        ->assertCreated()
        ->json('action_proposal');

    expect($body['status'])->toBe('pending');
    expect($alert->fresh()->is_resolved)->toBeFalse();
});

it('rejects a proposal with no rationale or an invalid action_type', function () {
    [$user, $org] = makeOrgUser('proposal-ep-invalid@example.com');
    $site = proposalEndpointSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/action-proposals", ['action_type' => 'resolve_alert', 'action_params' => []])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_rationale');

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/action-proposals", ['action_type' => 'nuke_site', 'rationale' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_action_type');
});

it('strips a smuggled secret/webhook_secret from action_params before ever storing it', function () {
    [$user, $org] = makeOrgUser('proposal-ep-strip-secret@example.com');
    $site = proposalEndpointSite($org);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/action-proposals", [
            'action_type' => 'send_webhook',
            'action_params' => ['url' => 'https://example.com/hook', 'secret' => 'sneaky', 'webhook_secret' => 'also-sneaky'],
            'rationale' => 'x',
        ])
        ->assertCreated()
        ->json('action_proposal');

    expect($body['action_params_json'])->not->toHaveKey('secret')
        ->and($body['action_params_json'])->not->toHaveKey('webhook_secret');
});

it('approves a proposal and executes the action', function () {
    [$user, $org] = makeOrgUser('proposal-ep-approve@example.com');
    $site = proposalEndpointSite($org);
    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);
    $proposal = ActionProposal::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'action_type' => 'resolve_alert',
        'action_params_json' => ['alert_id' => $alert->id], 'rationale' => 'x', 'status' => 'pending',
    ]);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/action-proposals/{$proposal->id}/approve")
        ->assertOk()
        ->json('action_proposal');

    expect($body['status'])->toBe('executed');
    expect($alert->fresh()->is_resolved)->toBeTrue();
});

it('rejects a proposal via the API without executing it', function () {
    [$user, $org] = makeOrgUser('proposal-ep-reject@example.com');
    $site = proposalEndpointSite($org);
    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);
    $proposal = ActionProposal::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'action_type' => 'resolve_alert',
        'action_params_json' => ['alert_id' => $alert->id], 'rationale' => 'x', 'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/action-proposals/{$proposal->id}/reject")
        ->assertOk()
        ->assertJsonPath('action_proposal.status', 'rejected');

    expect($alert->fresh()->is_resolved)->toBeFalse();
});

it('refuses to re-approve an already-executed proposal', function () {
    [$user, $org] = makeOrgUser('proposal-ep-reapprove@example.com');
    $site = proposalEndpointSite($org);
    $proposal = ActionProposal::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'action_type' => 'send_notification_email',
        'action_params_json' => ['subject' => 'S', 'body' => 'B'], 'rationale' => 'x', 'status' => 'executed',
        'reviewed_by_user_id' => $user->id, 'reviewed_at' => now(), 'executed_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/action-proposals/{$proposal->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_pending');
});

it('never lets a user approve, reject, or even see a proposal outside their organization', function () {
    [$userA, $orgA] = makeOrgUser('proposal-ep-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('proposal-ep-iso-b@example.com');
    $siteA = proposalEndpointSite($orgA);
    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $siteA->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);
    $proposal = ActionProposal::create([
        'organization_id' => $orgA->id, 'site_id' => $siteA->id, 'action_type' => 'resolve_alert',
        'action_params_json' => ['alert_id' => $alert->id], 'rationale' => 'x', 'status' => 'pending',
    ]);

    $this->actingAs($userB)->getJson("/api/sites/{$siteA->id}/action-proposals/{$proposal->id}")->assertNotFound();
    $this->actingAs($userB)->postJson("/api/sites/{$siteA->id}/action-proposals/{$proposal->id}/approve")->assertNotFound();
    $this->actingAs($userB)->postJson("/api/sites/{$siteA->id}/action-proposals/{$proposal->id}/reject")->assertNotFound();

    // Proves the attempted cross-org approve truly never executed anything.
    expect($proposal->fresh()->status)->toBe('pending')
        ->and($alert->fresh()->is_resolved)->toBeFalse();
});
