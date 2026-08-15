<?php

use App\Models\AiInsight;
use App\Models\Alert;
use App\Models\Anomaly;
use App\Models\AutomationRule;
use App\Models\EvidenceRun;
use App\Models\Investigation;
use App\Models\Organization;
use App\Models\ReportSchedule;
use App\Models\SavedInsight;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Nested `sites/{site}/thing/{id}` routes bind the wrong record.
 *
 * Laravel injects route parameters positionally. Controllers on these routes
 * read the Site off the request (EnsureSiteAccess resolves it) and so were
 * written to take only the second id — which meant the *site* id landed in the
 * record id, and every one of these endpoints operated on whichever record
 * happened to share the site's id.
 *
 * It is invisible on a single-site tenant whose first record is id 1, which is
 * exactly the shape of most dev fixtures. Each test below therefore picks a
 * target whose id is guaranteed to differ from the site's.
 */

function bindingSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => 'Binding',
        'domain'            => 'binding-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
    ]);
}

/**
 * Pick a record whose id differs from the site's.
 *
 * Ids are consecutive, so at most one row can collide — but which one depends
 * on how many rows earlier tests burned off the sequence, so the target has to
 * be chosen rather than assumed.
 */
function bindingTarget(Collection $rows, Site $site)
{
    $target = $rows->first(fn ($r) => $r->id !== $site->id);

    expect($target)->not->toBeNull();

    return $target;
}

it('acts on the requested insight, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-insight@example.com');
    $site = bindingSite($org);

    $rows = collect(range(1, 3))->map(fn ($n) => AiInsight::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'site_id'         => $site->id,
        'type'            => 'traffic',
        'title'           => "Insight {$n}",
        'summary'         => 'Body',
        'severity'        => 'low',
        'priority_score'  => 3,
        'status'          => 'unread',
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/insights/{$target->id}/dismiss")
        ->assertOk()
        ->assertJsonPath('insight.id', $target->id);

    expect($target->fresh()->dismissed_at)->not->toBeNull()
        // Nothing else may have moved.
        ->and($rows->reject(fn ($r) => $r->id === $target->id)
            ->every(fn ($r) => $r->fresh()->dismissed_at === null))->toBeTrue();
});

it('resolves the requested alert, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-alert@example.com');
    $site = bindingSite($org);

    $rows = collect(range(1, 3))->map(fn ($n) => Alert::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'site_id'         => $site->id,
        'type'            => 'traffic_drop',
        'severity'        => 'medium',
        'title'           => "Alert {$n}",
        'message'         => 'Body',
        'is_resolved'     => false,
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/alerts/{$target->id}/resolve")
        ->assertOk()
        ->assertJsonPath('alert.id', $target->id);

    expect($target->fresh()->is_resolved)->toBeTrue()
        ->and($rows->reject(fn ($r) => $r->id === $target->id)
            ->every(fn ($r) => $r->fresh()->is_resolved === false))->toBeTrue();
});

it('updates the requested report schedule, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-schedule@example.com');
    $site = bindingSite($org);

    $rows = collect(range(1, 3))->map(fn ($n) => ReportSchedule::create([
        'site_id'    => $site->id,
        'frequency'  => 'weekly',
        'recipients' => "owner{$n}@example.com",
        'is_active'  => true,
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->patchJson("/api/sites/{$site->id}/report-schedules/{$target->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('schedule.id', $target->id);

    expect((bool) $target->fresh()->is_active)->toBeFalse()
        ->and($rows->reject(fn ($r) => $r->id === $target->id)
            ->every(fn ($r) => (bool) $r->fresh()->is_active === true))->toBeTrue();
});

it('shows the requested evidence run, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-evidence@example.com');
    $site = bindingSite($org);

    $rows = collect(range(1, 3))->map(fn ($n) => EvidenceRun::create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'source' => 'woocommerce',
        'run_type' => 'query',
        'validator_version' => 'v1',
        'semantic_plan_json' => ['metrics' => ['net_revenue']],
        'metric_versions_json' => ['net_revenue' => 1],
        'status' => 'success',
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/evidence/{$target->id}")
        ->assertOk()
        ->assertJsonPath('run.id', $target->id);
});

it('updates the requested saved insight, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-saved-insight@example.com');
    $site = bindingSite($org);

    $rows = collect(range(1, 3))->map(fn ($n) => SavedInsight::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'owner_user_id' => $user->id,
        'name' => "Insight {$n}", 'semantic_plan_json' => ['metrics' => ['net_revenue']],
        'metric_versions_json' => ['net_revenue' => 1], 'chart_spec_json' => ['type' => 'kpi'],
        'scope' => 'organization', 'version' => 1,
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->patchJson("/api/sites/{$site->id}/saved-insights/{$target->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('saved_insight.id', $target->id);

    expect($target->fresh()->name)->toBe('Renamed')
        ->and($rows->reject(fn ($r) => $r->id === $target->id)
            ->every(fn ($r) => $r->fresh()->name === $r->name))->toBeTrue();
});

it('acknowledges the requested anomaly, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-anomaly@example.com');
    $site = bindingSite($org);

    $rows = collect(range(1, 3))->map(fn ($n) => Anomaly::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'metric_id' => 'net_revenue',
        'date' => now()->subDays($n)->toDateString(), 'value' => 1000 + $n, 'baseline_median' => 100,
        'mad' => 10, 'score' => 5.0, 'direction' => 'spike', 'severity' => 'high',
        'is_acknowledged' => false, 'detected_at' => now(),
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/anomalies/{$target->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('anomaly.id', $target->id);

    expect($target->fresh()->is_acknowledged)->toBeTrue()
        ->and($rows->reject(fn ($r) => $r->id === $target->id)
            ->every(fn ($r) => $r->fresh()->is_acknowledged === false))->toBeTrue();
});

it('updates the requested automation rule, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-automation-rule@example.com');
    $site = bindingSite($org);

    $rows = collect(range(1, 3))->map(fn ($n) => AutomationRule::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'name' => "Rule {$n}",
        'is_active' => true, 'trigger_type' => 'schedule', 'trigger_config_json' => [],
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->patchJson("/api/sites/{$site->id}/automation-rules/{$target->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('automation_rule.id', $target->id);

    expect($target->fresh()->name)->toBe('Renamed')
        ->and($rows->reject(fn ($r) => $r->id === $target->id)
            ->every(fn ($r) => $r->fresh()->name === $r->name))->toBeTrue();
});

it('shows the requested investigation, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-investigation@example.com');
    $site = bindingSite($org);

    $rows = collect(range(1, 3))->map(fn ($n) => Investigation::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'trigger_source' => 'manual',
        'subject' => "Subject {$n}", 'status' => 'completed',
        'findings_json' => [], 'narrative_json' => [],
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/investigations/{$target->id}")
        ->assertOk()
        ->assertJsonPath('investigation.id', $target->id);
});

it('shows the requested action proposal, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-action-proposal@example.com');
    $site = bindingSite($org);

    $rows = collect(range(1, 3))->map(fn ($n) => \App\Models\ActionProposal::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'action_type' => 'send_notification_email',
        'action_params_json' => ['subject' => 'S', 'body' => 'B'], 'rationale' => "Reason {$n}", 'status' => 'pending',
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/action-proposals/{$target->id}")
        ->assertOk()
        ->assertJsonPath('action_proposal.id', $target->id);
});

it('shows the requested outcome verification, not the one matching the site id', function () {
    [$user, $org] = makeOrgUser('bind-outcome-verification@example.com');
    $site = bindingSite($org);

    $proposal = \App\Models\ActionProposal::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'source_type' => 'manual',
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
        'rationale' => 'x', 'status' => 'executed', 'reviewed_at' => now(), 'executed_at' => now(),
    ]);

    $rows = collect(range(1, 3))->map(fn ($n) => \App\Models\OutcomeVerification::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'action_proposal_id' => $proposal->id,
        'watched_metric_id' => null, 'outcome' => 'no_metric_to_verify', 'verified_at' => now(),
    ]));

    $target = bindingTarget($rows, $site);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/outcome-verifications/{$target->id}")
        ->assertOk()
        ->assertJsonPath('outcome_verification.id', $target->id);
});
