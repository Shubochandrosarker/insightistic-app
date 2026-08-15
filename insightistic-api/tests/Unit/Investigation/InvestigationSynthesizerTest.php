<?php

use App\Metrics\MetricRegistry;
use App\Models\Organization;
use App\Models\Site;
use App\Query\QueryResult;
use App\Query\SemanticQueryPlan;
use App\Services\Ai\ProviderResolver;
use App\Services\Investigation\InvestigationSynthesizer;
use App\Support\DateRange;

function investigationResult(string $metricId, float $value, ?float $delta = null): QueryResult
{
    $plan = new SemanticQueryPlan(
        metricIds: [$metricId], source: 'woocommerce', dimension: null,
        periodPreset: 'last_30_days', periodFrom: null, periodTo: null,
        comparison: $delta !== null, limit: null,
    );

    return new QueryResult(
        queryHash: 'h', plan: $plan, range: DateRange::fromRequest('last_30_days', null, null, 'UTC'),
        metrics: [$metricId => $value], previous: $delta !== null ? [$metricId => 0] : null,
        deltas: $delta !== null ? [$metricId => $delta] : null, rows: [], rowCount: 1,
        truncated: false, durationMs: 1.0, readOnlyIdentityActive: true, caveats: [],
    );
}

it('never lets an AI response override confidence or citation_refs', function () {
    $registry = new MetricRegistry;
    $org = new Organization;
    $org->id = 1;
    $org->allow_external_ai = true;
    $site = new Site(['organization_id' => 1, 'timezone' => 'UTC']);
    $site->setRelation('organization', $org);

    $lying = new class implements \App\Services\Ai\AiProvider
    {
        public function model(): string
        {
            return 'lying-investigator';
        }

        public function generate(string $system, array $context, ?int $identityId = null): ?array
        {
            return [
                'direct_answer' => 'Revenue is secretly $99,999,999.',
                'key_findings' => ['Fabricated finding'],
                'recommended_actions' => ['Do something drastic'],
                'confidence' => 'high',
                'citation_refs' => ['fabricated:1'],
            ];
        }
    };

    $synthesizer = new InvestigationSynthesizer($registry, $lying, new ProviderResolver);
    $results = ['net_revenue' => investigationResult('net_revenue', 500.0)];
    $findings = [['metric_id' => 'net_revenue', 'status' => 'success', 'evidence_run_id' => 1, 'value' => 500.0, 'previous' => null, 'delta_pct' => null]];
    // Stale enough (>2 days) that the deterministic scorer computes
    // 'medium' — deliberately NOT what the lying provider claims ('high'),
    // so a passing assertion below actually proves the override never
    // happened rather than coincidentally matching.
    $freshness = ['data_as_of' => now()->subDays(5)->toDateString(), 'days_stale' => 5];

    $synth = $synthesizer->synthesize($site, 'Why?', $results, $findings, $freshness);

    expect($synth['contract']['direct_answer'])->toContain('99,999,999') // AI narration text is used...
        ->and($synth['contract']['confidence'])->toBe('medium') // ...but confidence is NEVER taken from the AI — its 'high' claim is ignored
        ->and($synth['contract']['citation_refs'])->toBe([]) // never taken from the AI — filled in by InvestigationService from real evidence run ids
        ->and($synth['ai']['used'])->toBeTrue();
});

it('produces a fully deterministic contract with no AI call when external AI is off', function () {
    $registry = new MetricRegistry;
    $org = new Organization;
    $org->id = 1;
    $org->allow_external_ai = false;
    $site = new Site(['organization_id' => 1, 'timezone' => 'UTC']);
    $site->setRelation('organization', $org);

    $exploding = new class implements \App\Services\Ai\AiProvider
    {
        public function model(): string
        {
            return 'should-not-be-called';
        }

        public function generate(string $system, array $context, ?int $identityId = null): ?array
        {
            throw new \RuntimeException('AI must not be called when consent is off.');
        }
    };

    $synthesizer = new InvestigationSynthesizer($registry, $exploding, new ProviderResolver);
    $results = ['net_revenue' => investigationResult('net_revenue', 500.0)];
    $findings = [['metric_id' => 'net_revenue', 'status' => 'success', 'evidence_run_id' => 1, 'value' => 500.0, 'previous' => null, 'delta_pct' => null]];
    $freshness = ['data_as_of' => now()->toDateString(), 'days_stale' => 0];

    $synth = $synthesizer->synthesize($site, 'Why?', $results, $findings, $freshness);

    expect($synth['contract']['narration'])->toBe('rule')->and($synth['ai']['used'])->toBeFalse();
});
