<?php

use App\Models\ActionProposal;
use App\Models\Anomaly;
use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\OutcomeVerification;
use App\Models\Site;

function commandOutcomeSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Command Outcome Shop',
        'domain' => 'cmd-outcome-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

it('verifies a due row and skips one whose baseline is too recent', function () {
    [, $org] = makeOrgUser('cmd-outcome-run@example.com');
    $site = commandOutcomeSite($org);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);
    $anomaly = Anomaly::create(['organization_id' => $org->id, 'site_id' => $site->id, 'metric_id' => 'net_revenue', 'date' => now()->toDateString(), 'value' => 100, 'baseline_median' => 50, 'mad' => 5, 'score' => 5.0, 'direction' => 'spike', 'severity' => 'high', 'is_acknowledged' => false, 'detected_at' => now()]);
    $proposal = ActionProposal::create(['organization_id' => $org->id, 'site_id' => $site->id, 'source_type' => 'anomaly', 'source_id' => $anomaly->id, 'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'], 'rationale' => 'x', 'status' => 'executed', 'reviewed_at' => now(), 'executed_at' => now()]);

    $due = OutcomeVerification::create(['organization_id' => $org->id, 'site_id' => $site->id, 'action_proposal_id' => $proposal->id, 'watched_metric_id' => 'net_revenue', 'baseline_value' => 100.0]);
    // Bypass Eloquent's auto-touch so created_at actually lands in the past.
    OutcomeVerification::where('id', $due->id)->update(['created_at' => now()->subHours(48)]);

    $notDue = OutcomeVerification::create(['organization_id' => $org->id, 'site_id' => $site->id, 'action_proposal_id' => $proposal->id, 'watched_metric_id' => 'net_revenue', 'baseline_value' => 100.0]);
    // created_at defaults to now() — not due yet.

    $this->artisan('insightistic:outcome-verifications-run')->assertSuccessful();

    expect($due->fresh()->isVerified())->toBeTrue()
        ->and($notDue->fresh()->isVerified())->toBeFalse();
});
