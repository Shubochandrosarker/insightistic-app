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

    public function generate(string $system, array $context): ?array
    {
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
}
