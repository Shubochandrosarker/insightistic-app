<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\SavedInsight;
use App\Models\Site;

function siEndpointSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Saved Insight Shop',
        'domain' => 'si-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

it('creates a saved insight from a valid plan, freezing metric versions and chart type', function () {
    [$user, $org] = makeOrgUser('si-create@example.com');
    $site = siEndpointSite($org);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/saved-insights", [
            'name' => 'Monthly Revenue',
            'plan' => ['metrics' => ['net_revenue'], 'period' => ['preset' => 'last_30_days']],
        ])
        ->assertCreated()
        ->json('saved_insight');

    expect($body['name'])->toBe('Monthly Revenue')
        ->and($body['metric_versions_json'])->toBe(['net_revenue' => 1])
        ->and($body['chart_spec_json'])->toBe(['type' => 'kpi'])
        ->and($body['scope'])->toBe('organization')
        ->and($body['version'])->toBe(1);
});

it('rejects an invalid plan with the same error codes analytics/query would', function () {
    [$user, $org] = makeOrgUser('si-invalid-plan@example.com');
    $site = siEndpointSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/saved-insights", [
            'name' => 'Bad',
            'plan' => ['metrics' => ['not_a_real_metric']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_metric');
});

it('rejects a missing name', function () {
    [$user, $org] = makeOrgUser('si-no-name@example.com');
    $site = siEndpointSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/saved-insights", ['plan' => ['metrics' => ['net_revenue']]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_name');
});

it('lists org-shared insights plus the caller\'s own private ones, hiding others\' private insights', function () {
    [$ownerA, $org] = makeOrgUser('si-list-owner@example.com');
    $memberB = \App\Models\User::create(['name' => 'B', 'email' => 'si-list-member@example.com', 'password' => 'secret-password', 'status' => 'active']);
    $org->users()->attach($memberB->id, ['role' => 'analyst']);
    $site = siEndpointSite($org);

    SavedInsight::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'owner_user_id' => $ownerA->id,
        'name' => 'Shared', 'semantic_plan_json' => ['metrics' => ['net_revenue']],
        'metric_versions_json' => ['net_revenue' => 1], 'chart_spec_json' => ['type' => 'kpi'],
        'scope' => 'organization', 'version' => 1,
    ]);
    SavedInsight::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'owner_user_id' => $ownerA->id,
        'name' => 'Owner-private', 'semantic_plan_json' => ['metrics' => ['net_revenue']],
        'metric_versions_json' => ['net_revenue' => 1], 'chart_spec_json' => ['type' => 'kpi'],
        'scope' => 'private', 'version' => 1,
    ]);

    $asB = $this->actingAs($memberB)->getJson("/api/sites/{$site->id}/saved-insights")->assertOk()->json('saved_insights');
    expect(collect($asB)->pluck('name')->all())->toBe(['Shared']);

    $asA = $this->actingAs($ownerA)->getJson("/api/sites/{$site->id}/saved-insights")->assertOk()->json('saved_insights');
    expect(collect($asA)->pluck('name'))->toContain('Shared', 'Owner-private');
});

it('404s fetching another user\'s private insight, but allows the owner', function () {
    [$owner, $org] = makeOrgUser('si-private-owner@example.com');
    $stranger = \App\Models\User::create(['name' => 'S', 'email' => 'si-private-stranger@example.com', 'password' => 'secret-password', 'status' => 'active']);
    $org->users()->attach($stranger->id, ['role' => 'analyst']);
    $site = siEndpointSite($org);

    $insight = SavedInsight::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'owner_user_id' => $owner->id,
        'name' => 'Mine', 'semantic_plan_json' => ['metrics' => ['net_revenue']],
        'metric_versions_json' => ['net_revenue' => 1], 'chart_spec_json' => ['type' => 'kpi'],
        'scope' => 'private', 'version' => 1,
    ]);

    $this->actingAs($stranger)
        ->getJson("/api/sites/{$site->id}/saved-insights/{$insight->id}")
        ->assertNotFound();

    $this->actingAs($owner)
        ->getJson("/api/sites/{$site->id}/saved-insights/{$insight->id}")
        ->assertOk()
        ->assertJsonPath('saved_insight.id', $insight->id);
});

it('updates name/scope without touching the plan, and bumps version only when the plan changes', function () {
    [$user, $org] = makeOrgUser('si-update@example.com');
    $site = siEndpointSite($org);

    $insight = SavedInsight::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'owner_user_id' => $user->id,
        'name' => 'Original', 'semantic_plan_json' => ['metrics' => ['net_revenue']],
        'metric_versions_json' => ['net_revenue' => 1], 'chart_spec_json' => ['type' => 'kpi'],
        'scope' => 'organization', 'version' => 1,
    ]);

    $renamed = $this->actingAs($user)
        ->patchJson("/api/sites/{$site->id}/saved-insights/{$insight->id}", ['name' => 'Renamed'])
        ->assertOk()->json('saved_insight');
    expect($renamed['name'])->toBe('Renamed')->and($renamed['version'])->toBe(1);

    $replanned = $this->actingAs($user)
        ->patchJson("/api/sites/{$site->id}/saved-insights/{$insight->id}", [
            'plan' => ['metrics' => ['orders']],
        ])
        ->assertOk()->json('saved_insight');
    expect($replanned['semantic_plan_json']['metrics'])->toBe(['orders'])
        ->and($replanned['version'])->toBe(2);
});

it('deletes a saved insight', function () {
    [$user, $org] = makeOrgUser('si-delete@example.com');
    $site = siEndpointSite($org);
    $insight = SavedInsight::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'owner_user_id' => $user->id,
        'name' => 'ToDelete', 'semantic_plan_json' => ['metrics' => ['net_revenue']],
        'metric_versions_json' => ['net_revenue' => 1], 'chart_spec_json' => ['type' => 'kpi'],
        'scope' => 'organization', 'version' => 1,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/sites/{$site->id}/saved-insights/{$insight->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(SavedInsight::find($insight->id))->toBeNull();
});

it('runs a saved insight, recording a fresh evidence run and updating last_run_at', function () {
    [$user, $org] = makeOrgUser('si-run@example.com');
    $site = siEndpointSite($org);
    MetricSnapshot::create([
        'site_id' => $site->id, 'date' => now()->toDateString(),
        'revenue' => 750, 'orders' => 3, 'refunds' => 0, 'average_order_value' => 250,
        'new_customers' => 1, 'returning_customers' => 2, 'products_sold' => 5, 'failed_orders' => 0,
    ]);
    $insight = SavedInsight::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'owner_user_id' => $user->id,
        'name' => 'Revenue', 'semantic_plan_json' => ['metrics' => ['net_revenue']],
        'metric_versions_json' => ['net_revenue' => 1], 'chart_spec_json' => ['type' => 'kpi'],
        'scope' => 'organization', 'version' => 1,
    ]);
    expect($insight->last_run_at)->toBeNull();

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/saved-insights/{$insight->id}/run")
        ->assertOk()
        ->json();

    expect($body['metrics']['net_revenue'])->toEqual(750.0) // JSON doesn't distinguish 750 from 750.0
        ->and($body['chart']['type'])->toBe('kpi')
        ->and($body['saved_insight_id'])->toBe($insight->id)
        ->and($body)->toHaveKey('evidence_run_id');

    expect($insight->fresh()->last_run_at)->not->toBeNull();

    $run = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/evidence/{$body['evidence_run_id']}")
        ->assertOk()->json('run');
    expect($run['run_type'])->toBe('query');
});

it('records a failed run as evidence when the stored plan\'s metric no longer resolves', function () {
    [$user, $org] = makeOrgUser('si-run-fail@example.com');
    $site = siEndpointSite($org);
    // A plan that was valid when saved but references a metric id that no
    // longer exists — simulates a metric being retired from the registry.
    $insight = SavedInsight::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'owner_user_id' => $user->id,
        'name' => 'Stale', 'semantic_plan_json' => ['metrics' => ['a_removed_metric'], 'source' => 'woocommerce'],
        'metric_versions_json' => ['a_removed_metric' => 1], 'chart_spec_json' => ['type' => 'kpi'],
        'scope' => 'organization', 'version' => 1,
    ]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/saved-insights/{$insight->id}/run")
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_metric');

    $runs = $this->actingAs($user)->getJson("/api/sites/{$site->id}/evidence")->assertOk()->json('runs');
    expect($runs)->toHaveCount(1)->and($runs[0]['status'])->toBe('error');
});

it('never lets a saved insight or its run leak across organizations', function () {
    [$userA, $orgA] = makeOrgUser('si-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('si-iso-b@example.com');
    $siteA = siEndpointSite($orgA);

    $insight = SavedInsight::create([
        'organization_id' => $orgA->id, 'site_id' => $siteA->id, 'owner_user_id' => $userA->id,
        'name' => 'A only', 'semantic_plan_json' => ['metrics' => ['net_revenue']],
        'metric_versions_json' => ['net_revenue' => 1], 'chart_spec_json' => ['type' => 'kpi'],
        'scope' => 'organization', 'version' => 1,
    ]);

    $this->actingAs($userB)
        ->getJson("/api/sites/{$siteA->id}/saved-insights/{$insight->id}")
        ->assertNotFound();

    $this->actingAs($userB)
        ->postJson("/api/sites/{$siteA->id}/saved-insights/{$insight->id}/run")
        ->assertNotFound();
});
