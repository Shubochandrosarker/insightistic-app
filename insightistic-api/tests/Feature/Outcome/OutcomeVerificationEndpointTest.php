<?php

use App\Models\ActionProposal;
use App\Models\Anomaly;
use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\OutcomeVerification;
use App\Models\Site;

function outcomeEpSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Outcome Endpoint Shop',
        'domain' => 'outcome-ep-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

it('lists, shows, and verifies an outcome verification through approving a proposal', function () {
    [$user, $org] = makeOrgUser('outcome-ep-flow@example.com');
    $site = outcomeEpSite($org);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 400, 'orders' => 4, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 1, 'failed_orders' => 0]);
    $anomaly = Anomaly::create(['organization_id' => $org->id, 'site_id' => $site->id, 'metric_id' => 'net_revenue', 'date' => now()->toDateString(), 'value' => 400, 'baseline_median' => 100, 'mad' => 10, 'score' => 5.0, 'direction' => 'spike', 'severity' => 'high', 'is_acknowledged' => false, 'detected_at' => now()]);
    $proposal = ActionProposal::create(['organization_id' => $org->id, 'site_id' => $site->id, 'source_type' => 'anomaly', 'source_id' => $anomaly->id, 'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'], 'rationale' => 'x', 'status' => 'pending']);

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/action-proposals/{$proposal->id}/approve")->assertOk();

    $listed = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/outcome-verifications")
        ->assertOk()
        ->json('outcome_verifications');
    expect($listed)->toHaveCount(1);
    $id = $listed[0]['id'];

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/outcome-verifications/{$id}")
        ->assertOk()
        ->assertJsonPath('outcome_verification.watched_metric_id', 'net_revenue');

    MetricSnapshot::query()->where('site_id', $site->id)->update(['revenue' => 500]);

    $verified = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/outcome-verifications/{$id}/verify")
        ->assertOk()
        ->json('outcome_verification');
    expect($verified['outcome'])->toBeIn(['improved', 'unchanged', 'worsened']);
});

it('refuses to re-verify an already-verified row', function () {
    [$user, $org] = makeOrgUser('outcome-ep-reverify@example.com');
    $site = outcomeEpSite($org);
    $proposal = ActionProposal::create(['organization_id' => $org->id, 'site_id' => $site->id, 'source_type' => 'manual', 'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'], 'rationale' => 'x', 'status' => 'executed', 'reviewed_at' => now(), 'executed_at' => now()]);
    $verification = OutcomeVerification::create(['organization_id' => $org->id, 'site_id' => $site->id, 'action_proposal_id' => $proposal->id, 'watched_metric_id' => null, 'outcome' => 'no_metric_to_verify', 'verified_at' => now()]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/outcome-verifications/{$verification->id}/verify")
        ->assertStatus(422)
        ->assertJsonPath('code', 'cannot_verify');
});

it('never lets an outcome verification leak across organizations', function () {
    [$userA, $orgA] = makeOrgUser('outcome-ep-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('outcome-ep-iso-b@example.com');
    $siteA = outcomeEpSite($orgA);
    $proposal = ActionProposal::create(['organization_id' => $orgA->id, 'site_id' => $siteA->id, 'source_type' => 'manual', 'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'], 'rationale' => 'x', 'status' => 'executed', 'reviewed_at' => now(), 'executed_at' => now()]);
    $verification = OutcomeVerification::create(['organization_id' => $orgA->id, 'site_id' => $siteA->id, 'action_proposal_id' => $proposal->id, 'watched_metric_id' => null, 'outcome' => 'no_metric_to_verify', 'verified_at' => now()]);

    $this->actingAs($userB)->getJson("/api/sites/{$siteA->id}/outcome-verifications/{$verification->id}")->assertNotFound();
    $this->actingAs($userB)->postJson("/api/sites/{$siteA->id}/outcome-verifications/{$verification->id}/verify")->assertNotFound();
});
