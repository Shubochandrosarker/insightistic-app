<?php

use App\Models\ActionProposal;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Outcome\OutcomeVerificationService;

function outcomeSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Outcome Shop',
        'domain' => 'outcome-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

function manualProposal(Organization $org, Site $site): ActionProposal
{
    return ActionProposal::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'source_type' => 'manual',
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
        'rationale' => 'x', 'status' => 'executed', 'reviewed_at' => now(), 'executed_at' => now(),
    ]);
}

beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

it('records no_metric_to_verify immediately for a manual (source-less) proposal', function () {
    [, $org] = makeOrgUser('outcome-manual@example.com');
    $site = outcomeSite($org);
    $proposal = manualProposal($org, $site);

    $verification = app(OutcomeVerificationService::class)->captureBaseline($proposal, $site);

    expect($verification->watched_metric_id)->toBeNull()
        ->and($verification->outcome)->toBe('no_metric_to_verify')
        ->and($verification->isVerified())->toBeTrue(); // already terminal, not left dangling
});

it('captures a real baseline value for an anomaly-sourced proposal', function () {
    [, $org] = makeOrgUser('outcome-anomaly@example.com');
    $site = outcomeSite($org);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 500, 'orders' => 5, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 1, 'failed_orders' => 0]);
    $anomaly = Anomaly::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'metric_id' => 'net_revenue',
        'date' => now()->toDateString(), 'value' => 500, 'baseline_median' => 100, 'mad' => 10,
        'score' => 5.0, 'direction' => 'spike', 'severity' => 'high', 'is_acknowledged' => false, 'detected_at' => now(),
    ]);
    $proposal = ActionProposal::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'source_type' => 'anomaly', 'source_id' => $anomaly->id,
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
        'rationale' => 'x', 'status' => 'executed', 'reviewed_at' => now(), 'executed_at' => now(),
    ]);

    $verification = app(OutcomeVerificationService::class)->captureBaseline($proposal, $site);

    expect($verification->watched_metric_id)->toBe('net_revenue')
        ->and($verification->baseline_value)->toEqual(500.0)
        ->and($verification->baseline_evidence_run_id)->not->toBeNull()
        ->and($verification->isVerified())->toBeFalse();
});

it('captures a real baseline value for an investigation-sourced proposal, using its first successful finding\'s metric', function () {
    [, $org] = makeOrgUser('outcome-investigation@example.com');
    $site = outcomeSite($org);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 200, 'orders' => 2, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 1, 'failed_orders' => 0]);
    $investigation = Investigation::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'trigger_source' => 'manual',
        'subject' => 'X', 'status' => 'completed',
        'findings_json' => [
            ['metric_id' => 'orders', 'status' => 'error', 'error' => 'unknown_metric'],
            ['metric_id' => 'net_revenue', 'status' => 'success', 'evidence_run_id' => 1, 'value' => 100.0],
        ],
        'narrative_json' => [],
    ]);
    $proposal = ActionProposal::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'source_type' => 'wpagentistic_investigation', 'source_id' => $investigation->id,
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
        'rationale' => 'x', 'status' => 'executed', 'reviewed_at' => now(), 'executed_at' => now(),
    ]);

    $verification = app(OutcomeVerificationService::class)->captureBaseline($proposal, $site);

    expect($verification->watched_metric_id)->toBe('net_revenue') // skips the error finding, uses the first success
        ->and($verification->baseline_value)->toEqual(200.0);
});

it('classifies improved/worsened/unchanged correctly against the noise band', function () {
    [, $org] = makeOrgUser('outcome-classify@example.com');
    $site = outcomeSite($org);
    $service = app(OutcomeVerificationService::class);

    $anomaly = Anomaly::create(['organization_id' => $org->id, 'site_id' => $site->id, 'metric_id' => 'net_revenue', 'date' => now()->toDateString(), 'value' => 100, 'baseline_median' => 100, 'mad' => 10, 'score' => 5.0, 'direction' => 'spike', 'severity' => 'high', 'is_acknowledged' => false, 'detected_at' => now()]);

    // Baseline 100 -> followup 150 (+50%) = improved.
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);
    $proposal = ActionProposal::create(['organization_id' => $org->id, 'site_id' => $site->id, 'source_type' => 'anomaly', 'source_id' => $anomaly->id, 'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'], 'rationale' => 'x', 'status' => 'executed', 'reviewed_at' => now(), 'executed_at' => now()]);
    $verification = $service->captureBaseline($proposal, $site);

    MetricSnapshot::query()->where('site_id', $site->id)->update(['revenue' => 150]);
    $verified = $service->verify($verification, $site);

    expect($verified->outcome)->toBe('improved')
        ->and($verified->followup_value)->toEqual(150.0)
        ->and($verified->delta_pct)->toEqual(50.0)
        ->and($verified->isVerified())->toBeTrue();
});

it('refuses to verify an already-verified row or one with no metric', function () {
    [, $org] = makeOrgUser('outcome-double-verify@example.com');
    $site = outcomeSite($org);
    $service = app(OutcomeVerificationService::class);
    $proposal = manualProposal($org, $site);
    $noMetric = $service->captureBaseline($proposal, $site);

    expect(fn () => $service->verify($noMetric, $site))->toThrow(\RuntimeException::class);
});

it('automatically captures a baseline when ActionProposalService::approve() succeeds', function () {
    [$user, $org] = makeOrgUser('outcome-auto-capture@example.com');
    $site = outcomeSite($org);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 300, 'orders' => 3, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 1, 'failed_orders' => 0]);
    $anomaly = Anomaly::create(['organization_id' => $org->id, 'site_id' => $site->id, 'metric_id' => 'net_revenue', 'date' => now()->toDateString(), 'value' => 300, 'baseline_median' => 100, 'mad' => 10, 'score' => 5.0, 'direction' => 'spike', 'severity' => 'high', 'is_acknowledged' => false, 'detected_at' => now()]);

    $service = app(\App\Services\Actions\ActionProposalService::class);
    $proposal = $service->propose($site, 'send_notification_email', ['subject' => 'S', 'body' => 'B'], 'x', $user->id, 'anomaly', $anomaly->id);
    $service->approve($proposal, $site, $user->id);

    $verification = \App\Models\OutcomeVerification::where('action_proposal_id', $proposal->id)->first();
    expect($verification)->not->toBeNull()
        ->and($verification->watched_metric_id)->toBe('net_revenue')
        ->and($verification->baseline_value)->toEqual(300.0);
});
