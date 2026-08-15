<?php

namespace App\Services\Agency;

use App\Models\Organization;
use App\Models\Site;
use App\Query\QueryEngineException;
use App\Query\SafeWooQueryEngine;

/**
 * Cross-site rollup for an agency-style organization (spec: Agency
 * Intelligence, P10) — "how are all my clients' sites doing right now, in
 * one view." See docs/AGENCY-INTELLIGENCE.md.
 *
 * Reuses `SafeWooQueryEngine` once per site the organization owns — no new
 * query path, no new tenant-scope risk: every site queried is already
 * scoped to `$org->id` by the `Site::where('organization_id', ...)` lookup
 * before this class ever touches the query engine, and the engine itself
 * additionally scopes every query by the `Site` object passed in.
 */
class AgencyRollupService
{
    /** Mirrors AlertDetector::REVENUE_DROP_THRESHOLD — a site "at risk" by the identical definition an alert would already flag. */
    private const AT_RISK_THRESHOLD = -20.0;

    public function __construct(private readonly SafeWooQueryEngine $engine) {}

    public function rollup(Organization $org, string $periodPreset = 'last_30_days'): array
    {
        $sites = Site::query()->where('organization_id', $org->id)->orderBy('name')->get();

        $totalRevenue = 0.0;
        $totalOrders = 0;
        $perSite = [];

        foreach ($sites as $site) {
            $rawPlan = ['metrics' => ['net_revenue', 'orders'], 'period' => ['preset' => $periodPreset], 'comparison' => true];

            try {
                $result = $this->engine->run($rawPlan, $site, $org->id);
                $revenue = (float) ($result->metrics['net_revenue'] ?? 0.0);
                $orders = (int) ($result->metrics['orders'] ?? 0);
                $revenueDelta = $result->deltas['net_revenue'] ?? null;

                $totalRevenue += $revenue;
                $totalOrders += $orders;

                $perSite[] = [
                    'site_id' => $site->id,
                    'site_name' => $site->name,
                    'status' => 'ok',
                    'net_revenue' => round($revenue, 2),
                    'orders' => $orders,
                    'net_revenue_delta_pct' => $revenueDelta,
                    'health' => $this->health($revenueDelta),
                ];
            } catch (QueryEngineException $e) {
                // A site with no synced data yet (or a plan that fails for
                // any other reason) is surfaced as its own row with an
                // honest error, never silently dropped from the rollup or
                // counted as zero revenue.
                $perSite[] = [
                    'site_id' => $site->id,
                    'site_name' => $site->name,
                    'status' => 'error',
                    'error' => $e->errorCode,
                ];
            }
        }

        return [
            'organization_id' => $org->id,
            'period' => $periodPreset,
            'site_count' => $sites->count(),
            'totals' => [
                'net_revenue' => round($totalRevenue, 2),
                'orders' => $totalOrders,
            ],
            'sites' => $perSite,
        ];
    }

    private function health(?float $revenueDeltaPct): string
    {
        return match (true) {
            $revenueDeltaPct === null => 'unknown',
            $revenueDeltaPct <= self::AT_RISK_THRESHOLD => 'at_risk',
            $revenueDeltaPct < 0 => 'watch',
            default => 'healthy',
        };
    }
}
