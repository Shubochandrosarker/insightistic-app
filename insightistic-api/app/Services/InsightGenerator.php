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
        $raw = $this->provider->generate(self::SYSTEM, $context);

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
}
