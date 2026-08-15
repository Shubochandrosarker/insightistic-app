<?php

namespace App\Services\Ask;

use App\Metrics\MetricRegistry;
use App\Query\QueryEngineException;

/**
 * Turns a free-text question into a raw plan array — the SAME shape
 * PlanValidator already accepts from a hand-authored request
 * (`{metrics, dimension, period, comparison, limit}`). Deterministic and
 * keyword-based on purpose (spec: Ask Insightistic V2, P3): this is the
 * primary, fully-tested path. An optional AI enhancement step may run
 * afterward (see AskInsightisticService), but its output is re-run through
 * this same validator boundary too — nothing an AI proposes ever skips
 * PlanValidator's allowlist checks.
 *
 * Every metric keyword resolved here comes from App\Metrics\MetricRegistry
 * (the Semantic Metric Registry, P0) — never a hardcoded metric id not
 * already registered, so this class can never "invent" a metric that
 * doesn't exist.
 */
class QuestionPlanBuilder
{
    /**
     * Metric id => keyword phrases that, if present in the lowercased
     * question, count as a match. Longer/more specific phrases are listed
     * first per metric only for readability — matching itself doesn't
     * depend on order within a metric's own list.
     */
    private const METRIC_KEYWORDS = [
        'net_revenue' => ['net revenue', 'revenue', 'sales', 'income', 'earnings', 'how much did we make', 'money made'],
        'orders' => ['order count', 'number of orders', 'orders', 'purchases', 'transactions'],
        'aov' => ['average order value', 'aov', 'basket size', 'average order'],
        'refund_count' => ['refunded orders', 'refund count', 'how many refunds'],
        'refund_rate' => ['refund rate', 'refund percentage', 'percent refunded'],
        'failed_orders' => ['failed orders', 'failed payments', 'declined orders', 'declined payments'],
        'products_sold' => ['products sold', 'units sold', 'items sold'],
        'product_revenue' => ['top products', 'top selling products', 'best selling products', 'revenue by product', 'which products', 'which product'],
        'new_customers' => ['new customers', 'new clients', 'first-time customers'],
        'returning_customers' => ['returning customers', 'repeat customers', 'repeat buyers'],
        'repeat_purchase_rate' => ['repeat purchase rate', 'repeat rate', 'customer retention rate', 'retention rate'],
        'ga4_sessions' => ['sessions', 'visits', 'traffic', 'visitors'],
        'ga4_conversions' => ['conversions', 'conversion count'],
        'conversion_rate' => ['conversion rate', 'cvr'],
        'organic_clicks' => ['organic clicks', 'search clicks'],
        'organic_impressions' => ['impressions', 'search impressions'],
        'organic_ctr' => ['organic ctr', 'search ctr', 'click through rate', 'click-through rate'],
        'organic_avg_position' => ['average position', 'search ranking', 'search position', 'ranking position'],
        'lcp' => ['largest contentful paint', 'lcp', 'page load time', 'load speed'],
        'inp' => ['interaction to next paint', 'inp', 'responsiveness'],
        'cls' => ['cumulative layout shift', 'cls', 'layout shift'],
    ];

    /** Phrases that select a "by product" breakdown instead of a site-level aggregate. */
    private const DIMENSION_TRIGGERS = [
        'product' => ['by product', 'per product', 'top products', 'top selling', 'best selling', 'which product', 'breakdown by product'],
    ];

    /** Phrases that request a previous-period comparison — only honored for an aggregate (no dimension). */
    private const COMPARISON_TRIGGERS = [
        'compared to', 'vs last', 'versus last', 'vs. last', 'compare with', 'compare to',
        'change from last', 'change vs', 'growth vs', 'growth compared',
    ];

    /** Phrases mapped to a PlanValidator period preset, longest/most-specific first. */
    private const PERIOD_TRIGGERS = [
        'last 7 days' => 'last_7_days',
        'past 7 days' => 'last_7_days',
        'this week' => 'last_7_days',
        'last 30 days' => 'last_30_days',
        'past 30 days' => 'last_30_days',
        'past month' => 'last_30_days',
        'this month' => 'this_month',
        'last month' => 'last_month',
        'previous month' => 'last_month',
        'yesterday' => 'yesterday',
        'today' => 'today',
    ];

    public function __construct(private readonly MetricRegistry $registry) {}

    /**
     * @return array{plan: array, matched_metrics: string[]} raw plan (PlanValidator-shaped) plus which metric ids the question actually matched, for confidence scoring.
     *
     * @throws QueryEngineException  ::questionNotUnderstood() when no metric keyword matched at all.
     */
    public function build(string $question): array
    {
        $text = strtolower(trim($question));

        if ($text === '') {
            throw QueryEngineException::questionNotUnderstood();
        }

        $matched = $this->matchMetrics($text);
        $dimension = $this->matchDimension($text);

        if ($matched === [] && $dimension === null) {
            throw QueryEngineException::questionNotUnderstood();
        }

        if ($dimension !== null) {
            $matched = $this->restrictToDimension($matched, $dimension);
        }

        if ($matched === []) {
            throw QueryEngineException::questionNotUnderstood();
        }

        $comparison = $dimension === null && $this->matchComparison($text);
        $limit = $dimension !== null ? $this->matchLimit($text) : null;

        $plan = array_filter([
            'metrics' => $matched,
            'dimension' => $dimension,
            'period' => ['preset' => $this->matchPeriod($text)],
            'comparison' => $comparison,
            'limit' => $limit,
        ], fn ($v) => $v !== null);

        return ['plan' => $plan, 'matched_metrics' => $matched];
    }

    /** @return string[] registered metric ids whose keywords appear in the question, in registry-stable order. */
    private function matchMetrics(string $text): array
    {
        $matched = [];
        foreach (self::METRIC_KEYWORDS as $id => $keywords) {
            if (! $this->registry->has($id)) {
                continue; // catalog entry not registered in this environment — never match a dangling id
            }
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $matched[] = $id;
                    break;
                }
            }
        }

        return array_values(array_unique($matched));
    }

    private function matchDimension(string $text): ?string
    {
        foreach (self::DIMENSION_TRIGGERS as $dimension => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($text, $phrase)) {
                    return $dimension;
                }
            }
        }

        // "top 5 products", "top 10 products by revenue" etc — a number
        // between "top" and "product(s)" that the literal phrase list above
        // can't match with a plain substring check.
        if (preg_match('/top\s+\d+\s+products?/', $text)) {
            return 'product';
        }

        return null;
    }

    /** @param  string[]  $matched */
    private function restrictToDimension(array $matched, string $dimension): array
    {
        $supporting = array_values(array_filter(
            $matched,
            fn (string $id) => in_array($dimension, $this->registry->get($id)->allowedDimensions, true)
        ));

        if ($supporting !== []) {
            return $supporting;
        }

        // The question asked for a breakdown but only matched metrics that
        // don't support one (e.g. "revenue" + "by product" both matched —
        // "revenue" alone doesn't carry a product dimension). Fall back to
        // the one registered metric this catalog declares for this
        // dimension, when it exists, rather than silently dropping the
        // breakdown the user explicitly asked for.
        foreach ($this->registry->all() as $definition) {
            if (in_array($dimension, $definition->allowedDimensions, true)) {
                return [$definition->id];
            }
        }

        return $matched;
    }

    private function matchComparison(string $text): bool
    {
        foreach (self::COMPARISON_TRIGGERS as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function matchPeriod(string $text): string
    {
        foreach (self::PERIOD_TRIGGERS as $phrase => $preset) {
            if (str_contains($text, $phrase)) {
                return $preset;
            }
        }

        return 'last_30_days';
    }

    private function matchLimit(string $text): ?int
    {
        if (preg_match('/top\s+(\d{1,3})/', $text, $m)) {
            return max(1, min(100, (int) $m[1]));
        }

        return null;
    }
}
