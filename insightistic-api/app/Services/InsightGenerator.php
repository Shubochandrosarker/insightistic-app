<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\Site;
use App\Services\Ai\AiProvider;
use App\Support\DateRange;

class InsightGenerator
{
    private const SYSTEM = <<<'TXT'
You are a senior ecommerce business analyst. Analyze the provided WooCommerce and
WordPress business data for the period. Return ONLY a JSON object with these keys:
"title" (short headline), "summary" (2-4 sentences), "possible_reason" (why it happened),
"recommendation" (one practical next action), "severity" (one of: low, medium, high),
"priority_score" (integer 1-10).
Rules: Do not invent data. Use only the provided metrics. Keep it practical and written
for a small business owner. If data looks incomplete, say so plainly.
TXT;

    public function __construct(
        private MetricsAssembler $metrics,
        private AiProvider $provider,
    ) {}

    /** Pure: produce a normalized insight array without storing or metering. */
    public function summarize(Site $site, DateRange $range): ?array
    {
        $context = $this->metrics->bundle($site, $range);
        $raw = $this->provider->generate(self::SYSTEM, $context, $site->organization_id);

        if (! $raw) {
            return null;
        }

        return [
            'title'           => (string) ($raw['title'] ?? 'Business summary'),
            'summary'         => (string) ($raw['summary'] ?? ''),
            'possible_reason' => (string) ($raw['possible_reason'] ?? ''),
            'recommendation'  => (string) ($raw['recommendation'] ?? ''),
            'severity'        => $this->normalizeSeverity($raw['severity'] ?? 'medium'),
            'priority_score'  => $this->clampPriority($raw['priority_score'] ?? 5),
            'model'           => $this->provider->model(),
            'context'         => $context,
        ];
    }

    /** Generate + persist an AiInsight (caller handles usage limits/metering). */
    public function generate(Site $site, DateRange $range, string $type): ?AiInsight
    {
        $insight = $this->summarize($site, $range);
        if (! $insight) {
            return null;
        }

        return AiInsight::create([
            'site_id'          => $site->id, // organization_id auto-filled by tenant scope
            'type'             => $type,
            'title'            => $insight['title'],
            'summary'          => trim($insight['summary'] . ' ' . $insight['possible_reason']),
            'recommendation'   => $insight['recommendation'],
            'severity'         => $insight['severity'],
            'priority_score'   => $insight['priority_score'],
            'source_data_json' => $insight['context'],
            'ai_model'         => $insight['model'],
            'status'           => 'unread',
        ]);
    }

    private function normalizeSeverity(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['low', 'medium', 'high'], true) ? $value : 'medium';
    }

    private function clampPriority($value): int
    {
        $n = (int) $value;
        return max(1, min(10, $n ?: 5));
    }

    // ---- Contextual insights (spec §11) ----------------------------------------

    private const CONTEXTUAL_SYSTEM = <<<'TXT'
You are a senior WordPress/WooCommerce growth analyst. You are given real,
server-verified evidence for one specific area of a website's performance.
Return ONLY a JSON object with these keys:
"headline" (short, specific headline, under 12 words),
"summary" (2-4 plain-language sentences a business owner can act on),
"what_changed" (1-2 sentences describing the actual change in the evidence),
"why_it_may_matter" (1-2 sentences on business impact),
"actions" (array of 3-5 short, specific, prioritized next steps),
"severity" (one of: low, medium, high),
"confidence" (one of: low, medium, high — lower this if the evidence is sparse),
"data_limitations" (one sentence on what this analysis can't see; empty string if none).
Rules: use ONLY the evidence provided below. Never invent numbers, dates, or
sources not present in it. If the evidence is thin, say so plainly in
data_limitations and lower confidence rather than overstating certainty.
TXT;

    /**
     * Generate one of the spec §11.1 context types (executive, traffic, seo,
     * performance, commerce, products, customers, content, alerts, report).
     * Takes an already-assembled context (see ContextAssembler) rather than
     * gathering evidence itself — the caller decides whether there's enough
     * data to bother calling the AI provider at all (InsightController
     * checks `has_data` before this is ever invoked, so no usage is metered
     * for a "not enough data yet" response).
     */
    public function generateContextual(Site $site, string $type, array $context): ?AiInsight
    {
        $raw = $this->provider->generate(self::CONTEXTUAL_SYSTEM, ['type' => $type, 'evidence' => $context['raw']], $site->organization_id);

        if (! $raw) {
            return null;
        }

        $actions = is_array($raw['actions'] ?? null)
            ? array_slice(array_values(array_filter(array_map('strval', $raw['actions']))), 0, 5)
            : [];

        $contract = [
            'headline'          => (string) ($raw['headline'] ?? 'Insight'),
            'summary'           => (string) ($raw['summary'] ?? ''),
            'what_changed'      => (string) ($raw['what_changed'] ?? ''),
            'why_it_may_matter' => (string) ($raw['why_it_may_matter'] ?? ''),
            'evidence'          => $context['evidence'],
            'actions'           => $actions,
            'severity'          => $this->normalizeSeverity($raw['severity'] ?? 'medium'),
            'confidence'        => $this->normalizeConfidence($raw['confidence'] ?? 'medium'),
            'data_limitations'  => (string) ($raw['data_limitations'] ?? ''),
            'model'             => $this->provider->model(),
            'generated_at'      => now()->toIso8601String(),
            'expires_at'        => now()->addHours(6)->toIso8601String(),
        ];

        return AiInsight::create([
            'site_id'          => $site->id, // organization_id auto-filled by tenant scope
            'type'             => $type,
            'title'            => $contract['headline'],
            'summary'          => $contract['summary'],
            'recommendation'   => implode(' ', $contract['actions']),
            'severity'         => $contract['severity'],
            'priority_score'   => $this->severityToScore($contract['severity']),
            'source_data_json' => $contract,
            'ai_model'         => $contract['model'],
            'status'           => 'unread',
            // Mirrored out of the contract into a real column so freshness is
            // queryable — inside JSON nothing could filter or sort on it.
            'expires_at'       => $contract['expires_at'] ?? null,
        ]);
    }

    private function normalizeConfidence($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['low', 'medium', 'high'], true) ? $value : 'medium';
    }

    private function severityToScore(string $severity): int
    {
        return match ($severity) {
            'high'  => 9,
            'low'   => 2,
            default => 5,
        };
    }
}
