<?php

namespace App\Services\Crawler;

use App\Models\CrawlIssue;
use App\Models\SeoPageDaily;
use App\Models\Site;

/**
 * Site crawl health score (Phase 7, doc 12 weights).
 *
 * The score starts at 100 and subtracts a penalty for every OPEN issue,
 * weighted by severity, grouped by category:
 *
 *   category weights : crawlability 30, on_page 20, links 15,
 *                      content 10, structured_data 10
 *   severity points  : critical 12, high 6, medium 3, low 1
 *
 * Each category's penalty is CAPPED at its own weight, so a hundred open
 * low-severity on_page nits cost that category 20 points — not the whole
 * score. The five weights sum to 85; the remaining 15 points are headroom
 * deliberately reserved for the performance module (whose issues live in
 * PerformanceIssue, not CrawlIssue), so a site with zero crawl issues can
 * still lose points there without this score going negative first.
 *
 * calculate() is pure (takes plain issue arrays — unit-testable without a
 * database); forSite() is the thin query wrapper the health endpoint calls.
 *
 * Phase 8 adds the OPTIONAL GSC-impact mode (doc 12: "severity is not
 * purely technical — a missing title on a page with 100k impressions
 * should rank above the same issue on an unvisited archive page"):
 * calculateWeighted() multiplies each issue's severity points by an
 * impact factor taken from the page path's 28-day Search Console
 * impressions. calculate() stays untouched and remains the default.
 */
class CrawlHealthScore
{
    /** Penalty points per open issue, by severity. */
    public const SEVERITY_WEIGHTS = [
        'critical' => 12,
        'high'     => 6,
        'medium'   => 3,
        'low'      => 1,
    ];

    /** Max penalty a single category can contribute (= its doc-12 weight). */
    public const CATEGORY_WEIGHTS = [
        'crawlability'    => 30,
        'on_page'         => 20,
        'links'           => 15,
        'content'         => 10,
        'structured_data' => 10,
    ];

    /** GSC 28d impressions at which an issue's penalty doubles. */
    public const IMPACT_HIGH_IMPRESSIONS = 1000;

    /** GSC 28d impressions at which an issue's penalty scales 1.5x. */
    public const IMPACT_MEDIUM_IMPRESSIONS = 100;

    /** Sort order used for top_issues (worst first). */
    public const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'];

    /**
     * Pure score math over already-fetched issues. Each element needs
     * 'category' and 'severity'; anything else is ignored.
     *
     * @param array<int, array{category: string, severity: string}> $issues
     * @return array{score: int, breakdown: array<string, array{open_issues: int, penalty: int, weight: int}>}
     */
    public static function calculate(array $issues): array
    {
        $penalties = array_fill_keys(array_keys(self::CATEGORY_WEIGHTS), 0);
        $counts = array_fill_keys(array_keys(self::CATEGORY_WEIGHTS), 0);

        foreach ($issues as $issue) {
            $category = $issue['category'] ?? null;
            $severity = $issue['severity'] ?? null;

            if ($category === null || ! isset(self::CATEGORY_WEIGHTS[$category])) {
                continue;
            }

            $counts[$category]++;
            $penalties[$category] += self::SEVERITY_WEIGHTS[$severity] ?? 0;
        }

        // Cap per category at its own weight, then sum.
        $total = 0;
        $breakdown = [];
        foreach (self::CATEGORY_WEIGHTS as $category => $weight) {
            $penalty = min($penalties[$category], $weight);
            $total += $penalty;

            $breakdown[$category] = [
                'open_issues' => $counts[$category],
                'penalty'     => $penalty,
                'weight'      => $weight,
            ];
        }

        return [
            'score'     => (int) max(0, round(100 - $total)),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * GSC-weighted variant of calculate(): identical category weights,
     * caps and flooring, but every issue's severity points are multiplied
     * by impactFactor() of the target page's 28-day impressions before the
     * per-category cap is applied. Paths are host-stripped so a
     * target_url and a GSC page_path compare equal.
     *
     * @param array<int, array{category: string, severity: string, target_url?: string}> $issues
     * @param array<string, int> $impressionsByPath path => GSC 28d impressions
     * @return array{score: int, breakdown: array<string, array{open_issues: int, penalty: int, weight: int}>}
     */
    public static function calculateWeighted(array $issues, array $impressionsByPath): array
    {
        $penalties = array_fill_keys(array_keys(self::CATEGORY_WEIGHTS), 0.0);
        $counts = array_fill_keys(array_keys(self::CATEGORY_WEIGHTS), 0);

        foreach ($issues as $issue) {
            $category = $issue['category'] ?? null;
            $severity = $issue['severity'] ?? null;

            if ($category === null || ! isset(self::CATEGORY_WEIGHTS[$category])) {
                continue;
            }

            $counts[$category]++;
            $penalties[$category] += (self::SEVERITY_WEIGHTS[$severity] ?? 0) * self::impactFactor(
                $impressionsByPath[self::pathOf((string) ($issue['target_url'] ?? ''))] ?? 0,
            );
        }

        // Same bookkeeping as calculate(): cap per category, floor the score.
        $total = 0.0;
        $breakdown = [];
        foreach (self::CATEGORY_WEIGHTS as $category => $weight) {
            $penalty = min($penalties[$category], (float) $weight);
            $total += $penalty;

            $breakdown[$category] = [
                'open_issues' => $counts[$category],
                'penalty'     => (int) round($penalty),
                'weight'      => $weight,
            ];
        }

        return [
            'score'     => (int) max(0, round(100 - $total)),
            'breakdown' => $breakdown,
        ];
    }

    /** Traffic multiplier for one page: 1000+ impressions x2, 100+ x1.5, else 1.0. */
    public static function impactFactor(int $impressions): float
    {
        return match (true) {
            $impressions >= self::IMPACT_HIGH_IMPRESSIONS   => 2.0,
            $impressions >= self::IMPACT_MEDIUM_IMPRESSIONS => 1.5,
            default                                         => 1.0,
        };
    }

    /** Path-only form of a URL (host/query stripped); bare paths pass through. */
    private static function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return '/';
        }

        return $path;
    }

    /**
     * Score one site from its OPEN issues (the DB wrapper). top_issues is
     * the 5 worst open issues — severity first, most recently seen first
     * within a severity — so the UI never has to re-query.
     *
     * With $weighted the penalties are scaled by each target page's 28-day
     * GSC impressions and the result reports mode 'gsc_weighted'
     * ('uniform' otherwise), so callers can echo which mode produced the
     * score.
     *
     * @return array{score: int, breakdown: array, mode: string, top_issues: array<int, CrawlIssue>}
     */
    public function forSite(Site $site, bool $weighted = false): array
    {
        $issues = CrawlIssue::query()
            ->where('site_id', $site->id)
            ->where('status', 'open')
            ->get(['category', 'severity', 'issue_code', 'target_url']);

        $result = $weighted
            ? self::calculateWeighted($issues->all(), $this->impressionsByPath($site))
            : self::calculate($issues->map->only(['category', 'severity'])->all());

        $result['mode'] = $weighted ? 'gsc_weighted' : 'uniform';

        $topIssues = CrawlIssue::query()
            ->where('site_id', $site->id)
            ->where('status', 'open')
            // Constants come from SEVERITY_ORDER above, never user input.
            ->orderByRaw("array_position(ARRAY['" . implode("','", self::SEVERITY_ORDER) . "']::text[], severity)")
            ->orderByDesc('last_seen_at')
            ->limit(5)
            ->get([
                'id', 'issue_code', 'severity', 'category',
                'target_url', 'first_seen_at', 'last_seen_at',
            ]);

        return $result + ['top_issues' => $topIssues->all()];
    }

    /** Summed 28-day GSC impressions per page path for one site. */
    private function impressionsByPath(Site $site): array
    {
        return SeoPageDaily::query()
            ->where('site_id', $site->id)
            ->where('date', '>=', now()->subDays(28)->startOfDay())
            ->groupBy('page_path')
            ->selectRaw('page_path, sum(impressions) as total')
            ->pluck('total', 'page_path')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
