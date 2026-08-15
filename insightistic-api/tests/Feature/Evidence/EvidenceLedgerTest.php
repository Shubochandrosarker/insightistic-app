<?php

use App\Metrics\MetricRegistry;
use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;
use App\Query\EvidenceLedger;
use App\Query\PlanValidator;
use App\Query\QueryEngineException;
use App\Query\SafeWooQueryEngine;

function ledgerSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Ledger Shop',
        'domain' => 'ledger-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

beforeEach(function () {
    // See SafeWooQueryEngineTest.php's docblock — functional tests fall
    // back off the readonly connection so they can see same-transaction data.
    config(['database.connections.readonly.username' => null]);
});

it('records a successful query as a success row with frozen metric versions and freshness', function () {
    [, $org] = makeOrgUser('ledger-success@example.com');
    $site = ledgerSite($org);
    MetricSnapshot::create([
        'site_id' => $site->id, 'date' => now()->toDateString(),
        'revenue' => 500, 'orders' => 5, 'refunds' => 0, 'average_order_value' => 100,
        'new_customers' => 2, 'returning_customers' => 3, 'products_sold' => 8, 'failed_orders' => 0,
    ]);

    $result = app(SafeWooQueryEngine::class)->run(['metrics' => ['net_revenue', 'orders']], $site, $org->id);
    $run = app(EvidenceLedger::class)->recordSuccess($site, null, 'query', $result);

    $registry = new MetricRegistry();

    expect($run->status)->toBe('success')
        ->and($run->site_id)->toBe($site->id)
        ->and($run->organization_id)->toBe($org->id)
        ->and($run->source)->toBe('woocommerce')
        ->and($run->run_type)->toBe('query')
        ->and($run->validator_version)->toBe(PlanValidator::CONTRACT_VERSION)
        ->and($run->query_hash)->toBe($result->queryHash)
        ->and($run->row_count)->toBe(1)
        ->and($run->metric_versions_json)->toBe([
            'net_revenue' => $registry->get('net_revenue')->version,
            'orders' => $registry->get('orders')->version,
        ])
        ->and($run->freshness_json['data_as_of'])->toBe(now()->toDateString())
        ->and($run->freshness_json['days_stale'])->toBe(0)
        ->and($run->semantic_plan_json['metrics'])->toBe(['net_revenue', 'orders']);
});

it('records a failed query as an error row with a safe message, never a stack trace', function () {
    [, $org] = makeOrgUser('ledger-fail@example.com');
    $site = ledgerSite($org);

    $rawPlan = ['metrics' => ['not_a_real_metric']];
    $error = null;
    try {
        app(SafeWooQueryEngine::class)->run($rawPlan, $site, $org->id);
    } catch (QueryEngineException $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();

    $run = app(EvidenceLedger::class)->recordFailure($site, null, 'query', $rawPlan, $error);

    expect($run->status)->toBe('error')
        ->and($run->error_code)->toBe('unknown_metric')
        ->and($run->error_message)->toBe($error->getMessage())
        ->and($run->error_message)->not->toContain('Exception')
        ->and($run->error_message)->not->toContain('.php')
        ->and($run->row_count)->toBeNull()
        ->and($run->metric_versions_json)->toBe([]);
});

it('reports null freshness honestly when the site has never synced', function () {
    [, $org] = makeOrgUser('ledger-no-sync@example.com');
    $site = ledgerSite($org); // no MetricSnapshot rows at all

    $result = app(SafeWooQueryEngine::class)->run(['metrics' => ['net_revenue']], $site, $org->id);
    $run = app(EvidenceLedger::class)->recordSuccess($site, null, 'query', $result);

    expect($run->freshness_json)->toBe(['data_as_of' => null, 'days_stale' => null]);
});

// Tenant isolation across organizations is covered end-to-end, through the
// real auth/tenancy middleware stack, in EvidenceEndpointTest.php — not
// simulated here by hand-setting Tenancy, which would only prove the
// global scope mechanism works, not that the actual request pipeline does.
