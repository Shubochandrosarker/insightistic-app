<?php

use App\Metrics\MetricRegistry;
use App\Query\QueryEngineException;
use App\Services\Ask\QuestionPlanBuilder;

function planBuilder(): QuestionPlanBuilder
{
    return new QuestionPlanBuilder(new MetricRegistry);
}

it('maps a simple revenue question to a net_revenue aggregate plan', function () {
    $built = planBuilder()->build('What was our revenue last month?');

    expect($built['matched_metrics'])->toBe(['net_revenue'])
        ->and($built['plan']['metrics'])->toBe(['net_revenue'])
        ->and($built['plan']['dimension'] ?? null)->toBeNull()
        ->and($built['plan']['period']['preset'])->toBe('last_month');
});

it('defaults to last_30_days when no period phrase is present', function () {
    $built = planBuilder()->build('How many orders do we have?');

    expect($built['plan']['period']['preset'])->toBe('last_30_days');
});

it('detects a comparison request for an aggregate question', function () {
    $built = planBuilder()->build('How does revenue compare to last month?');

    expect($built['plan']['comparison'] ?? false)->toBeTrue();
});

it('routes a "top products" question to a product breakdown with a limit', function () {
    $built = planBuilder()->build('What are our top 5 products this month?');

    expect($built['plan']['dimension'])->toBe('product')
        ->and($built['plan']['metrics'])->toBe(['product_revenue'])
        ->and($built['plan']['limit'])->toBe(5)
        ->and($built['plan']['comparison'] ?? false)->toBeFalse();
});

it('never sets comparison alongside a dimension breakdown, even if both phrases appear', function () {
    $built = planBuilder()->build('Compare top products this month vs last month');

    expect($built['plan']['dimension'])->toBe('product')
        ->and($built['plan']['comparison'] ?? false)->toBeFalse();
});

it('matches multiple metrics from one question', function () {
    $built = planBuilder()->build('What was our revenue and order count today?');

    expect($built['matched_metrics'])->toEqualCanonicalizing(['net_revenue', 'orders'])
        ->and($built['plan']['period']['preset'])->toBe('today');
});

it('throws question_not_understood for a question with no known metric keyword', function () {
    planBuilder()->build('What is the meaning of life?');
})->throws(QueryEngineException::class);

it('throws question_not_understood for an empty question', function () {
    planBuilder()->build('   ');
})->throws(QueryEngineException::class);

it('never returns a metric id the registry does not have registered', function () {
    $registry = new MetricRegistry([]); // empty catalog
    $builder = new QuestionPlanBuilder($registry);

    expect(fn () => $builder->build('What was our revenue?'))
        ->toThrow(QueryEngineException::class);
});
