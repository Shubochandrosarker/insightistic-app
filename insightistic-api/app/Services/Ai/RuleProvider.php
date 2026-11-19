<?php

namespace App\Services\Ai;

/**
 * Deterministic, $0 fallback summarizer. Uses ONLY the provided metrics — no
 * invented data (spec Mistake #5) — to produce a structured insight. Powers
 * dev/testing without an API key and serves as a graceful fallback when an
 * org hits its AI quota. Pure PHP, no framework dependencies.
 */
class RuleProvider implements AiProvider
{
    public function model(): string
    {
        return 'rule-based-v1';
    }

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        // Contextual insight generation supplies an explicit type and an
        // already-authorized evidence bundle. It must never fall through to
        // the legacy WooCommerce revenue summary: that was why Performance
        // insights returned order figures and Executive insights ignored
        // completed audit/PageSpeed runs.
        if (isset($context['type']) && array_key_exists('evidence', $context)) {
            return $this->contextual((string) $context['type'], (array) $context['evidence']);
        }

        $m       = $context['metrics'] ?? [];
        $deltas  = $context['deltas'] ?? [];
        $currency = $context['site']['currency'] ?? 'USD';
        $revDelta = $deltas['revenue'] ?? null;

        $revenue = (float) ($m['revenue'] ?? 0);
        $orders  = (int) ($m['orders'] ?? 0);
        $aov     = (float) ($m['average_order_value'] ?? 0);
        $newC    = (int) ($m['new_customers'] ?? 0);
        $retC    = (int) ($m['returning_customers'] ?? 0);
        $refunds = (int) ($m['refunds'] ?? 0);

        $money = fn ($n) => $currency . ' ' . number_format($n, 2);

        // Direction + severity from revenue movement.
        if ($revDelta === null) {
            $title = 'Business summary for the period';
            $reason = 'No prior-period data was available to compare against.';
            $severity = 'low';
            $priority = 3;
        } elseif ($revDelta <= -15) {
            $title = sprintf('Revenue dropped %.1f%% versus the previous period', abs($revDelta));
            $reason = $retC < $newC
                ? 'Returning-customer activity slowed while new customers held up.'
                : 'Overall order volume fell across the period.';
            $severity = 'high';
            $priority = 8;
        } elseif ($revDelta < 0) {
            $title = sprintf('Revenue down %.1f%% — worth watching', abs($revDelta));
            $reason = 'A modest decline in orders or average order value.';
            $severity = 'medium';
            $priority = 5;
        } elseif ($revDelta >= 15) {
            $title = sprintf('Revenue up %.1f%% — strong period', $revDelta);
            $reason = 'Higher order volume and/or larger average order value drove growth.';
            $severity = 'low';
            $priority = 4;
        } else {
            $title = 'Steady performance this period';
            $reason = 'Revenue held roughly flat versus the previous period.';
            $severity = 'low';
            $priority = 3;
        }

        $summary = sprintf(
            'Revenue was %s across %d order(s) at an average order value of %s. New customers: %d, returning: %d. Refunded orders: %d.',
            $money($revenue), $orders, $money($aov), $newC, $retC, $refunds
        );

        // Practical recommendation tied to the data.
        if ($severity === 'high') {
            $rec = 'Launch a win-back campaign to customers who ordered 30–90 days ago, and review top-product availability.';
        } elseif ($refunds > 0 && $orders > 0 && ($refunds / max($orders, 1)) > 0.1) {
            $rec = 'Refund rate is elevated — review the most-refunded products and shipping/quality issues.';
        } elseif ($revDelta !== null && $revDelta >= 15) {
            $rec = 'Double down: increase stock on top sellers and consider a follow-up offer to recent buyers.';
        } else {
            $rec = 'Maintain momentum: nurture new customers toward a second order and keep best-sellers in stock.';
        }

        return [
            'title'           => $title,
            'summary'         => $summary,
            'possible_reason' => $reason,
            'recommendation'  => $rec,
            'severity'        => $severity,
            'priority_score'  => $priority,
        ];
    }

    /** @return array{headline:string,summary:string,what_changed:string,why_it_may_matter:string,actions:array,severity:string,confidence:string,data_limitations:string} */
    private function contextual(string $type, array $raw): array
    {
        return match ($type) {
            'performance', 'pagespeed' => $this->performanceContext($raw),
            'site_audit' => $this->auditContext($raw),
            'executive', 'report' => $this->executiveContext($raw),
            'traffic' => $this->metricContext('Traffic', $raw, ['sessions', 'users', 'conversions']),
            'seo' => $this->metricContext('Search visibility', $raw, ['clicks', 'impressions']),
            'commerce' => $this->commerceContext($raw),
            default => $this->metricContext(ucwords(str_replace('_', ' ', $type)), $raw, []),
        };
    }

    private function performanceContext(array $raw): array
    {
        $runs = array_values(array_filter((array) ($raw['runs'] ?? []), 'is_array'));
        if ($runs === [] && isset($raw['performance_score'])) {
            $runs = [$raw];
        }

        $scores = [];
        foreach ($runs as $run) {
            $device = ucfirst((string) ($run['strategy'] ?? 'device'));
            foreach (['performance_score', 'accessibility_score', 'best_practices_score', 'seo_score', 'ai_readiness_score'] as $key) {
                if (($run[$key] ?? null) !== null) {
                    $scores[] = $device.' '.str_replace('_', ' ', $key).': '.$run[$key];
                }
            }
        }

        $performance = array_values(array_filter(array_map(
            static fn (array $run) => isset($run['performance_score']) ? (int) $run['performance_score'] : null,
            $runs,
        ), static fn ($value) => $value !== null));
        $lowest = $performance === [] ? null : min($performance);
        $actions = [];
        if ($lowest !== null && $lowest < 90) {
            $actions[] = 'Review the stored PageSpeed opportunities for the device with the lowest performance score.';
        }
        if (array_filter($runs, static fn (array $run) => ($run['accessibility_score'] ?? null) === null)) {
            $actions[] = 'Run a fresh PageSpeed test if a supported category is still unavailable.';
        }
        $actions[] = 'Prioritize the largest Core Web Vitals or Lighthouse opportunity before re-testing.';

        return [
            'headline' => $lowest === null ? 'PageSpeed evidence is available' : "PageSpeed performance is {$lowest}/100 at its lowest",
            'summary' => $scores === []
                ? 'A completed PageSpeed context was supplied, but it contains no score fields to summarize.'
                : 'The stored PageSpeed evidence covers '.implode('; ', array_slice($scores, 0, 10)).'.',
            'what_changed' => 'This fallback uses PageSpeed fields only; it does not substitute commerce or revenue data.',
            'why_it_may_matter' => 'Performance, accessibility, SEO and derived AI-readiness scores describe the technical experience that visitors and crawlers receive.',
            'actions' => array_values(array_unique(array_slice($actions, 0, 5))),
            'severity' => $lowest !== null && $lowest < 50 ? 'high' : ($lowest !== null && $lowest < 90 ? 'medium' : 'low'),
            'confidence' => $scores !== [] ? 'high' : 'low',
            'data_limitations' => 'PageSpeed lab or field evidence is a snapshot and does not prove every page performs the same way.',
        ];
    }

    private function auditContext(array $raw): array
    {
        $run = (array) ($raw['run'] ?? []);
        $health = (array) ($raw['health'] ?? []);
        $pending = (bool) ($raw['pending'] ?? in_array($run['status'] ?? null, ['pending', 'running'], true));
        $crawled = (int) ($run['pages_crawled'] ?? 0);
        $discovered = (int) ($run['pages_discovered'] ?? 0);
        $issues = (int) ($raw['open_issue_count'] ?? $run['issues_found'] ?? 0);
        $score = $health['score'] ?? null;

        return [
            'headline' => $pending
                ? 'Site Audit is still processing'
                : ($score === null ? 'Site Audit completed without a health score' : "Site Audit health score: {$score}/100"),
            'summary' => sprintf(
                'The latest crawler status is %s with %d pages crawled, %d discovered and %d open issue(s).',
                $run['status'] ?? 'unknown', $crawled, $discovered, $issues,
            ),
            'what_changed' => $pending
                ? 'The audit is pending or running, so health scoring is intentionally withheld until finalization.'
                : 'The result is based on the latest completed crawler run and its current open issues.',
            'why_it_may_matter' => 'Accurate progress and classification prevent a temporary clean dashboard from being mistaken for a healthy completed crawl.',
            'actions' => [
                $pending ? 'Wait for the crawler to finalize before evaluating the health score.' : 'Review the highest-severity open issue examples first.',
                'Separate HTML page findings from robots.txt, sitemap.xml and other non-HTML resources.',
                'Re-run the audit after resolving the highest-impact issues and compare the completed counts.',
            ],
            'severity' => $pending ? 'medium' : (($score !== null && $score < 50) || $issues >= 20 ? 'high' : 'medium'),
            'confidence' => $run !== [] ? 'high' : 'low',
            'data_limitations' => $pending ? 'A pending crawl has no final health calculation yet.' : 'The stored summary does not replace opening each issue URL for remediation details.',
        ];
    }

    private function executiveContext(array $raw): array
    {
        $available = [];
        foreach ($raw as $source => $value) {
            if (is_array($value) && $value !== []) {
                $available[] = str_replace('_', ' ', (string) $source);
            }
        }
        $available = array_values(array_unique($available));
        $findings = [];
        $audit = $raw['site_audit'] ?? [];
        if (($audit['run']['status'] ?? null) === 'completed') {
            $findings[] = sprintf('Site Audit crawled %d pages and found %d issues.', $audit['run']['pages_crawled'], $audit['run']['issues_found']);
        }
        foreach ($raw['pagespeed']['runs'] ?? [] as $run) {
            $findings[] = sprintf('%s PageSpeed performance scored %d/100.', ucfirst($run['strategy']), $run['performance_score']);
        }
        $summary = $findings ? implode(' ', $findings) : 'Available sources: '.implode(', ', $available).'.';

        return [
            'headline' => $available === [] ? 'Executive evidence is limited' : 'Executive view combines verified sources',
            'summary' => $available === []
                ? 'The executive context did not contain a populated source bundle.'
                : $summary,
            'what_changed' => 'This fallback summarizes the non-empty source bundles selected by the executive context.',
            'why_it_may_matter' => 'A cross-source view makes it easier to connect technical health, search visibility, traffic and commerce without treating one source as the whole business.',
            'actions' => [
                'Review the source-specific findings before making a business decision.',
                'Confirm that the latest Site Audit and PageSpeed runs are complete and fresh.',
                'Use the cited evidence to choose one technical and one growth priority for the next cycle.',
            ],
            'severity' => $available === [] ? 'medium' : 'low',
            'confidence' => $available === [] ? 'low' : 'medium',
            'data_limitations' => 'The executive fallback does not infer trends that are not present in the supplied source bundles.',
        ];
    }

    private function commerceContext(array $raw): array
    {
        $metrics = (array) ($raw['metrics'] ?? $raw);

        return [
            'headline' => 'Commerce evidence is available',
            'summary' => 'Commerce analysis is limited to the supplied WooCommerce metrics: '.$this->scalarSummary($metrics).'.',
            'what_changed' => 'This output is intentionally scoped to commerce metrics.',
            'why_it_may_matter' => 'Orders, revenue and customer activity are the relevant signals for a commerce insight.',
            'actions' => ['Review the largest movement in the supplied commerce metrics.', 'Compare the period with a prior period when one is available.'],
            'severity' => 'low',
            'confidence' => $metrics !== [] ? 'medium' : 'low',
            'data_limitations' => 'No commerce conclusion is made beyond the metrics supplied by the server.',
        ];
    }

    private function metricContext(string $label, array $raw, array $preferred): array
    {
        $metrics = [];
        foreach ($preferred as $key) {
            if (array_key_exists($key, $raw)) {
                $metrics[$key] = $raw[$key];
            }
        }
        if ($metrics === []) {
            $metrics = array_filter($raw, static fn ($value) => is_scalar($value));
        }

        return [
            'headline' => $label.' evidence is available',
            'summary' => $metrics === [] ? "The {$label} context contains no compact scalar metrics." : "{$label} evidence: ".$this->scalarSummary($metrics).'.',
            'what_changed' => "This fallback uses {$label} fields only.",
            'why_it_may_matter' => "The supplied {$label} metrics should be reviewed in their source-specific dashboard context.",
            'actions' => ["Review the largest {$label} movement shown in the supplied evidence.", 'Compare with a prior period when the source provides one.'],
            'severity' => 'low',
            'confidence' => $metrics === [] ? 'low' : 'medium',
            'data_limitations' => "The fallback cannot infer a trend that is not present in the {$label} context.",
        ];
    }

    private function scalarSummary(array $metrics): string
    {
        $items = [];
        foreach (array_slice($metrics, 0, 8, true) as $key => $value) {
            if (is_scalar($value)) {
                $items[] = str_replace('_', ' ', (string) $key).'='.((string) $value);
            }
        }

        return $items === [] ? 'no scalar values' : implode(', ', $items);
    }
}
