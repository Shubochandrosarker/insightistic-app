<?php

namespace App\Services\Ask;

use App\Metrics\MetricRegistry;
use App\Models\Site;
use App\Query\QueryResult;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ProviderResolver;
use App\Services\Ai\RuleProvider;

/**
 * Turns a QueryResult into a narrated answer contract (spec: Ask
 * Insightistic V2, P3) — mirroring the shape CopilotChat's answer contract
 * already established (direct_answer, key_finding, supporting_metrics,
 * why_it_matters, recommended_actions, confidence, data_limitations,
 * citation_refs), so client UIs built for one can render the other.
 *
 * Two layers, always in this order:
 *   1. Deterministic template — built ONLY from QueryResult's actual
 *      numbers. This is the answer whenever no AI provider is configured,
 *      the organization disabled external AI (ProviderResolver), or the AI
 *      call fails for any reason. It is never skipped even when AI
 *      succeeds.
 *   2. Optional AI rephrase — narrative fields only (direct_answer,
 *      key_finding, why_it_matters, recommended_actions). `confidence`,
 *      `supporting_metrics` and `citation_refs` are NEVER taken from the AI
 *      response — they stay whatever the deterministic layer computed, so
 *      a model cannot invent a number, inflate its own confidence, or
 *      fabricate a citation no matter what it returns.
 */
class AnswerSynthesizer
{
    private const SYSTEM = <<<'TXT'
You are Insightistic Ask, a WooCommerce analytics narrator. You are given a
question and the EXACT evidence already computed by a safe query engine.
Rephrase the provided direct_answer/key_finding/why_it_matters into clearer,
more natural language for a business owner, and refine recommended_actions
into specific next steps. Rules: use ONLY the numbers already present in the
evidence — never introduce a number, date, percentage, or comparison that is
not already there. Do not change what the numbers mean. Return ONLY a JSON
object with keys: direct_answer, key_finding, why_it_matters,
recommended_actions (array of up to 4 short strings).
TXT;

    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly AiProvider $provider,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * @param  array{label: string, reasons: string[], freshness: array}  $confidence
     * @return array{contract: array, ai: array{used: bool, model: ?string}}  `contract.citation_refs` is left empty — the caller fills it in once EvidenceLedger has assigned a run id, since citing a run that doesn't exist yet isn't possible and citations are never AI-controlled anyway.
     */
    public function synthesize(Site $site, string $question, QueryResult $result, array $confidence): array
    {
        $contract = $this->deterministic($question, $result, $confidence);
        $ai = ['used' => false, 'model' => null];

        $enhanced = $this->tryAiRephrase($site, $question, $result, $contract);
        if ($enhanced !== null) {
            $contract['direct_answer'] = $enhanced['direct_answer'];
            $contract['key_finding'] = $enhanced['key_finding'];
            $contract['why_it_matters'] = $enhanced['why_it_matters'];
            $contract['recommended_actions'] = $enhanced['recommended_actions'];
            $contract['narration'] = 'ai';
            $ai = ['used' => true, 'model' => $enhanced['model']];
        }

        return ['contract' => $contract, 'ai' => $ai];
    }

    private function deterministic(string $question, QueryResult $result, array $confidence): array
    {
        if ($result->plan->isBreakdown()) {
            [$direct, $finding] = $this->narrateBreakdown($result);
        } else {
            [$direct, $finding] = $this->narrateAggregate($result);
        }

        $limitations = $confidence['reasons'] !== [] ? implode(' ', $confidence['reasons']) : '';

        return [
            'direct_answer' => $direct,
            'key_finding' => $finding,
            'supporting_metrics' => $this->supportingMetrics($result),
            'why_it_matters' => $this->whyItMatters($result),
            'recommended_actions' => $this->recommendedActions($result),
            'confidence' => $confidence['label'],
            'data_limitations' => $limitations,
            'citation_refs' => [],
            'narration' => 'rule',
        ];
    }

    private function narrateAggregate(QueryResult $result): array
    {
        $parts = [];
        foreach ($result->metrics as $id => $value) {
            $definition = $this->registry->get($id);
            $formatted = $this->format($value, $definition->unit);
            $delta = $result->deltas[$id] ?? null;
            $deltaText = $delta === null ? '' : sprintf(' (%s%.1f%% vs the previous period)', $delta >= 0 ? '+' : '', $delta);
            $parts[] = "{$definition->label} was {$formatted}{$deltaText}";
        }

        $direct = $parts === [] ? 'No metrics were available for this period.' : implode('. ', $parts) . '.';
        $finding = $parts === [] ? 'No data.' : reset($parts) . '.';

        return [$direct, $finding];
    }

    private function narrateBreakdown(QueryResult $result): array
    {
        if ($result->rows === []) {
            return ['No rows matched this breakdown for the selected period.', 'No data.'];
        }

        $top = $result->rows[0];
        $direct = sprintf(
            '%d item(s) were returned. The top one is "%s" with %s in revenue (%d unit(s) sold).',
            $result->rowCount,
            $top['name'] ?? 'Unknown',
            $this->format($top['revenue'] ?? 0, 'currency'),
            $top['units'] ?? 0,
        );
        $finding = sprintf('Top: "%s" — %s.', $top['name'] ?? 'Unknown', $this->format($top['revenue'] ?? 0, 'currency'));

        return [$direct, $finding];
    }

    private function supportingMetrics(QueryResult $result): array
    {
        if ($result->plan->isBreakdown()) {
            return array_map(fn (array $row) => [
                'label' => $row['name'] ?? 'Unknown',
                'value' => $this->format($row['revenue'] ?? 0, 'currency'),
            ], array_slice($result->rows, 0, 5));
        }

        $out = [];
        foreach ($result->metrics as $id => $value) {
            $definition = $this->registry->get($id);
            $out[] = ['label' => $definition->label, 'value' => $this->format($value, $definition->unit)];
        }

        return $out;
    }

    private function whyItMatters(QueryResult $result): ?string
    {
        if ($result->plan->isBreakdown() || $result->deltas === null) {
            return null;
        }

        foreach ($result->deltas as $id => $delta) {
            if ($delta !== null && abs($delta) >= 15) {
                $definition = $this->registry->get($id);
                $direction = $delta > 0 ? 'up' : 'down';

                return "{$definition->label} moved {$direction} more than 15% — worth a closer look.";
            }
        }

        return null;
    }

    private function recommendedActions(QueryResult $result): array
    {
        $actions = [];
        if ($result->caveats !== []) {
            $actions[] = 'Review the caveats below before acting on these numbers.';
        }
        if (! $result->readOnlyIdentityActive) {
            $actions[] = 'Note: this ran without the dedicated read-only database role configured — safe to trust, but ask an admin to finish setup for production.';
        }

        return $actions;
    }

    private function format(int|float|null $value, string $unit): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return match ($unit) {
            'currency' => number_format((float) $value, 2),
            'ratio' => number_format(((float) $value) * 100, 1) . '%',
            default => (string) $value,
        };
    }

    /** @return array{direct_answer: string, key_finding: string, why_it_matters: ?string, recommended_actions: string[], model: string}|null */
    private function tryAiRephrase(Site $site, string $question, QueryResult $result, array $deterministic): ?array
    {
        $provider = $this->providerResolver->resolve($this->provider, $site);

        // ProviderResolver falls back to RuleProvider when external AI is
        // off for this organization — that provider's generate() speaks a
        // different contract (business-insight fields, not this narration
        // contract) and would just be wasted work here, so skip the call
        // entirely rather than send a request whose response is discarded.
        if ($provider instanceof RuleProvider) {
            return null;
        }

        $evidence = [
            'question' => $question,
            'direct_answer' => $deterministic['direct_answer'],
            'key_finding' => $deterministic['key_finding'],
            'why_it_matters' => $deterministic['why_it_matters'],
            'metrics' => $result->metrics,
            'deltas' => $result->deltas,
            'rows' => array_slice($result->rows, 0, 5),
        ];

        $raw = $provider->generate(self::SYSTEM, $evidence, $site->organization_id);
        if (! is_array($raw) || empty($raw['direct_answer'])) {
            return null;
        }

        return [
            'direct_answer' => (string) $raw['direct_answer'],
            'key_finding' => (string) ($raw['key_finding'] ?? $deterministic['key_finding']),
            'why_it_matters' => isset($raw['why_it_matters']) ? (string) $raw['why_it_matters'] : $deterministic['why_it_matters'],
            'recommended_actions' => is_array($raw['recommended_actions'] ?? null)
                ? array_slice(array_values(array_filter(array_map('strval', $raw['recommended_actions']))), 0, 4)
                : $deterministic['recommended_actions'],
            'model' => $provider->model(),
        ];
    }
}
