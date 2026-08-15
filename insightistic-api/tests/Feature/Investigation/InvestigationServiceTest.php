<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Investigation\InvestigationService;

function investigationSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Investigation Shop',
        'domain' => 'investig-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

it('investigates multiple metrics, records one evidence run per metric, and cites them all', function () {
    [$user, $org] = makeOrgUser('investigate-multi@example.com');
    $site = investigationSite($org);
    MetricSnapshot::create([
        'site_id' => $site->id, 'date' => now()->toDateString(),
        'revenue' => 500, 'orders' => 5, 'refunds' => 1, 'average_order_value' => 100,
        'new_customers' => 2, 'returning_customers' => 3, 'products_sold' => 8, 'failed_orders' => 0,
    ]);

    $investigation = app(InvestigationService::class)->investigate(
        $site, $org->id, 'Why did revenue move?', ['net_revenue', 'refund_count'], $user->id,
    );

    expect($investigation->status)->toBe('completed')
        ->and($investigation->findings_json)->toHaveCount(2)
        ->and($investigation->findings_json[0]['status'])->toBe('success')
        ->and($investigation->narrative_json['citation_refs'])->toHaveCount(2)
        ->and($investigation->narrative_json['direct_answer'])->toContain('500');
});

it('records a finding error (not a thrown exception) for an unknown metric, and still completes for the rest', function () {
    [$user, $org] = makeOrgUser('investigate-partial@example.com');
    $site = investigationSite($org);
    MetricSnapshot::create([
        'site_id' => $site->id, 'date' => now()->toDateString(),
        'revenue' => 200, 'orders' => 2, 'refunds' => 0, 'average_order_value' => 100,
        'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 2, 'failed_orders' => 0,
    ]);

    $investigation = app(InvestigationService::class)->investigate(
        $site, $org->id, 'Mixed', ['net_revenue', 'not_a_real_metric'], $user->id,
    );

    expect($investigation->status)->toBe('completed') // at least one metric succeeded
        ->and(collect($investigation->findings_json)->firstWhere('metric_id', 'not_a_real_metric')['status'])->toBe('error')
        ->and(collect($investigation->findings_json)->firstWhere('metric_id', 'net_revenue')['status'])->toBe('success');
});

it('marks status error when every requested metric fails', function () {
    [$user, $org] = makeOrgUser('investigate-all-fail@example.com');
    $site = investigationSite($org);

    $investigation = app(InvestigationService::class)->investigate(
        $site, $org->id, 'All bad', ['not_real_1', 'not_real_2'], $user->id,
    );

    expect($investigation->status)->toBe('error')
        ->and($investigation->narrative_json['confidence'])->toBe('low');
});

it('caps the number of metrics investigated to PlanValidator::MAX_METRICS', function () {
    [$user, $org] = makeOrgUser('investigate-cap@example.com');
    $site = investigationSite($org);

    $many = ['net_revenue', 'orders', 'aov', 'refund_count', 'refund_rate', 'failed_orders', 'products_sold'];
    $investigation = app(InvestigationService::class)->investigate($site, $org->id, 'Cap test', $many, $user->id);

    expect($investigation->findings_json)->toHaveCount(\App\Query\PlanValidator::MAX_METRICS);
});

it('never lets an investigation see another site\'s data', function () {
    [, $org] = makeOrgUser('investigate-iso-a@example.com');
    [, $otherOrg] = makeOrgUser('investigate-iso-b@example.com');
    $site = investigationSite($org);
    $otherSite = investigationSite($otherOrg);

    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 111, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);
    MetricSnapshot::create(['site_id' => $otherSite->id, 'date' => now()->toDateString(), 'revenue' => 999999, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);

    $investigation = app(InvestigationService::class)->investigate($site, $org->id, 'Isolation', ['net_revenue'], null);

    // jsonb round-trips a whole-number float as an int — same reasoning
    // as SavedInsightEndpointTest's JSON-response assertion.
    expect($investigation->findings_json[0]['value'])->toEqual(111.0);
});
