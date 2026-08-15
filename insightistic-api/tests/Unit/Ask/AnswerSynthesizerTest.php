<?php

use App\Metrics\MetricRegistry;
use App\Models\Organization;
use App\Models\Site;
use App\Query\PlanValidator;
use App\Query\QueryResult;
use App\Query\SemanticQueryPlan;
use App\Services\Ai\ProviderResolver;
use App\Services\Ai\RuleProvider;
use App\Services\Ask\AnswerSynthesizer;
use App\Support\DateRange;

function aggregateResult(array $metrics, ?array $deltas = null): QueryResult
{
    $plan = new SemanticQueryPlan(
        metricIds: array_keys($metrics),
        source: 'woocommerce',
        dimension: null,
        periodPreset: 'last_30_days',
        periodFrom: null,
        periodTo: null,
        comparison: $deltas !== null,
        limit: null,
    );

    return new QueryResult(
        queryHash: 'hash',
        plan: $plan,
        range: DateRange::fromRequest('last_30_days', null, null, 'UTC'),
        metrics: $metrics,
        previous: $deltas !== null ? array_map(fn () => 0, $metrics) : null,
        deltas: $deltas,
        rows: [],
        rowCount: 1,
        truncated: false,
        durationMs: 1.0,
        readOnlyIdentityActive: true,
        caveats: [],
    );
}

it('never lets an AI response override confidence, supporting_metrics or citation_refs', function () {
    $registry = new MetricRegistry;
    $org = new Organization; // allow_external_ai isn't mass-assignable — set it directly below.
    $org->id = 1;
    $org->allow_external_ai = true;
    $site = new Site(['organization_id' => 1, 'timezone' => 'UTC']);
    $site->setRelation('organization', $org);

    $lyingProvider = new class implements \App\Services\Ai\AiProvider
    {
        public function model(): string
        {
            return 'lying-model';
        }

        public function generate(string $system, array $context, ?int $identityId = null): ?array
        {
            // Tries to smuggle a fabricated number and a fake confidence —
            // neither key exists in the answer contract's AI-writable set,
            // so both must be ignored.
            return [
                'direct_answer' => 'Revenue was actually $999,999,999 — trust me.',
                'key_finding' => 'Huge growth.',
                'why_it_matters' => 'Everything is amazing.',
                'recommended_actions' => ['Buy a yacht'],
                'confidence' => 'high',
                'supporting_metrics' => [['label' => 'Fake', 'value' => '999']],
                'citation_refs' => ['fabricated:1'],
            ];
        }
    };

    $synthesizer = new AnswerSynthesizer($registry, $lyingProvider, new ProviderResolver);
    $result = aggregateResult(['net_revenue' => 500.0]);
    $confidence = ['label' => 'medium', 'reasons' => ['test reason'], 'freshness' => []];

    $synth = $synthesizer->synthesize($site, 'What was our revenue?', $result, $confidence);

    expect($synth['contract']['direct_answer'])->toContain('999,999,999') // AI narration text is used...
        ->and($synth['contract']['confidence'])->toBe('medium') // ...but confidence is NEVER taken from the AI
        ->and($synth['contract']['citation_refs'])->toBe([]) // filled in later by the orchestrator from the real evidence run id, never from the AI
        ->and($synth['contract']['supporting_metrics'][0]['value'])->toBe('500.00') // real computed value, not the AI's "999"
        ->and($synth['ai']['used'])->toBeTrue()
        ->and($synth['ai']['model'])->toBe('lying-model');
});

it('produces a fully deterministic contract with no AI call when external AI is off', function () {
    $registry = new MetricRegistry;
    $org = new Organization;
    $org->id = 1;
    $org->allow_external_ai = false;
    $site = new Site(['organization_id' => 1, 'timezone' => 'UTC']);
    $site->setRelation('organization', $org);

    $explodingProvider = new class implements \App\Services\Ai\AiProvider
    {
        public function model(): string
        {
            return 'should-never-be-called';
        }

        public function generate(string $system, array $context, ?int $identityId = null): ?array
        {
            throw new \RuntimeException('AI must not be called when consent is off.');
        }
    };

    $synthesizer = new AnswerSynthesizer($registry, $explodingProvider, new ProviderResolver);
    $result = aggregateResult(['net_revenue' => 500.0]);
    $confidence = ['label' => 'high', 'reasons' => [], 'freshness' => []];

    $synth = $synthesizer->synthesize($site, 'What was our revenue?', $result, $confidence);

    expect($synth['contract']['narration'])->toBe('rule')
        ->and($synth['ai']['used'])->toBeFalse()
        ->and($synth['ai']['model'])->toBeNull();
});
