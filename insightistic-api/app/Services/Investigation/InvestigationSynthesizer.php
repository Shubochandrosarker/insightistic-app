<?php

namespace App\Services\Investigation;

use App\Metrics\MetricRegistry;
use App\Models\Site;
use App\Query\QueryResult;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ProviderResolver;
use App\Services\Ai\RuleProvider;

/**
 * Synthesizes a multi-metric Investigation (spec: WPAgentistic
 * Investigations, P8) into a narrative — the exact same two-layer
 * guarantee `App\Services\Ask\AnswerSynthesizer` (P3) established, applied
 * across several metrics/evidence runs instead of one query result:
 *
 *   1. Deterministic — built ONLY from the real QueryResult values already
 *      computed for each requested metric. This is the complete answer
 *      whenever no AI provider is configured, the organization disabled
 *      external AI, or the AI call fails.
 *   2. Optional AI rephrase — narrative text ONLY (direct_answer,
 *      key_findings, recommended_actions). `confidence` and
 *      `citation_refs` are never read from the AI response under any
 *      circumstance.
 */
class InvestigationSynthesizer
{
    private const SYSTEM = <<<'TXT'
You are WPAgentistic, a read-only diagnostic analyst for a WordPress/WooCommerce
business. You are given a question and the EXACT evidence already computed
by a safe query engine across several metrics. Rephrase the provided
direct_answer/key_findings into clearer, more natural language for a
business owner, and refine recommended_actions into specific next steps.
Rules: use ONLY the numbers already present in the evidence — never
introduce a number, date, percentage, or comparison that is not already
there. You cannot take any action, only analyze. Return ONLY a JSON object
with keys: direct_answer, key_findings (array of strings), recommended_actions
(array of up to 5 short strings).
TXT;

    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly AiProvider $provider,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * @param  array<string, QueryResult>  $resultsByMetricId  Successful runs only.
     * @param  array<int, array>  $findings  One entry per requested metric, success or error — see InvestigationService.
     * @param  array{data_as_of: ?string, days_stale: ?int}  $freshness
     * @return array{contract: array, ai: array{used: bool, model: ?string}}
     */
    public function synthesize(Site $site, string $subject, array $resultsByMetricId, array $findings, array $freshness): array
    {
        $contract = $this->deterministic($subject, $resultsByMetricId, $findings, $freshness);
        $ai = ['used' => false, 'model' => null];

        $enhanced = $this->tryAiRephrase($site, $subject, $resultsByMetricId, $contract);
        if ($enhanced !== null) {
            $contract['direct_answer'] = $enhanced['direct_answer'];
            $contract['key_findings'] = $enhanced['key_findings'];
            $contract['recommended_actions'] = $enhanced['recommended_actions'];
            $contract['narration'] = 'ai';
            $ai = ['used' => true, 'model' => $enhanced['model']];
        }

        return ['contract' => $contract, 'ai' => $ai];
    }

    private function deterministic(string $subject, array $resultsByMetricId, array $findings, array $freshness): array
    {
        $keyFindings = [];
        $errors = 0;

        foreach ($findings as $finding) {
            if ($finding['status'] !== 'success') {
                $errors++;
                $keyFindings[] = "Could not evaluate \"{$finding['metric_id']}\" ({$finding['error']}).";

                continue;
            }

            $metricId = $finding['metric_id'];
            $result = $resultsByMetricId[$metricId] ?? null;
            if (! $result) {
                continue;
            }

            $definition = $this->registry->get($metricId);
            $value = $result->metrics[$metricId] ?? null;
            $delta = $result->deltas[$metricId] ?? null;
            $formatted = $this->format($value, $definition->unit);
            $deltaText = $delta === null ? '' : sprintf(' (%s%.1f%% vs the previous period)', $delta >= 0 ? '+' : '', $delta);
            $keyFindings[] = "{$definition->label}: {$formatted}{$deltaText}.";
        }

        $direct = $keyFindings === []
            ? 'No metrics could be evaluated for this investigation.'
            : sprintf('Investigated "%s" across %d metric(s): %s', $subject, count($findings), implode(' ', $keyFindings));

        $confidence = $this->confidence($freshness, $errors, count($findings));
        $limitations = [];
        if ($freshness['days_stale'] === null) {
            $limitations[] = 'This site has no synced data yet.';
        } elseif ($freshness['days_stale'] > 2) {
            $limitations[] = "Underlying data is {$freshness['days_stale']} day(s) stale.";
        }
        if ($errors > 0) {
            $limitations[] = "{$errors} of ".count($findings)." requested metric(s) could not be evaluated.";
        }

        return [
            'direct_answer' => $direct,
            'key_findings' => $keyFindings,
            'recommended_actions' => [],
            'confidence' => $confidence,
            'data_limitations' => implode(' ', $limitations),
            'citation_refs' => [],
            'narration' => 'rule',
        ];
    }

    private function confidence(array $freshness, int $errors, int $total): string
    {
        if ($freshness['days_stale'] === null || $errors === $total) {
            return 'low';
        }
        if ($freshness['days_stale'] > 2 || $errors > 0) {
            return 'medium';
        }

        return 'high';
    }

    private function format(int|float|null $value, string $unit): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return match ($unit) {
            'currency' => number_format((float) $value, 2),
            'ratio' => number_format(((float) $value) * 100, 1).'%',
            default => (string) $value,
        };
    }

    /** @return array{direct_answer: string, key_findings: string[], recommended_actions: string[], model: string}|null */
    private function tryAiRephrase(Site $site, string $subject, array $resultsByMetricId, array $deterministic): ?array
    {
        $provider = $this->providerResolver->resolve($this->provider, $site);

        if ($provider instanceof RuleProvider) {
            return null; // consent off or unconfigured — never call, matches AnswerSynthesizer's identical guard
        }

        $evidence = [
            'subject' => $subject,
            'direct_answer' => $deterministic['direct_answer'],
            'key_findings' => $deterministic['key_findings'],
            'metrics' => array_map(fn (QueryResult $r) => $r->metrics, $resultsByMetricId),
            'deltas' => array_map(fn (QueryResult $r) => $r->deltas, $resultsByMetricId),
        ];

        $raw = $provider->generate(self::SYSTEM, $evidence, $site->organization_id);
        if (! is_array($raw) || empty($raw['direct_answer'])) {
            return null;
        }

        return [
            'direct_answer' => (string) $raw['direct_answer'],
            'key_findings' => is_array($raw['key_findings'] ?? null)
                ? array_values(array_filter(array_map('strval', $raw['key_findings'])))
                : $deterministic['key_findings'],
            'recommended_actions' => is_array($raw['recommended_actions'] ?? null)
                ? array_slice(array_values(array_filter(array_map('strval', $raw['recommended_actions']))), 0, 5)
                : $deterministic['recommended_actions'],
            'model' => $provider->model(),
        ];
    }
}
