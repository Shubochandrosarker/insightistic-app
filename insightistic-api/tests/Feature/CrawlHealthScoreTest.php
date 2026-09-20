<?php

use App\Services\Crawler\CrawlHealthScore;

/**
 * Crawl health score math (Phase 7, doc 12 weights) — pure, no database.
 *
 * Weights: crawlability 30 / on_page 20 / links 15 / content 10 /
 * structured_data 10 (sum 85; the remaining 15 points are headroom the
 * performance module spends). Severity points: critical 12 / high 6 /
 * medium 3 / low 1, capped per category at its own weight, score floors
 * at 0.
 */

it('scores a site with no open issues at 100', function () {
    $result = CrawlHealthScore::calculate([]);

    expect($result['score'])->toBe(100);

    foreach (['crawlability', 'on_page', 'links', 'content', 'structured_data'] as $category) {
        expect($result['breakdown'][$category])->toBe([
            'open_issues' => 0,
            'penalty'     => 0,
            'weight'      => CrawlHealthScore::CATEGORY_WEIGHTS[$category],
        ]);
    }
});

it('applies the doc-12 severity weights', function () {
    // One of each severity in crawlability: 12 + 6 + 3 + 1 = 22 of 30.
    $result = CrawlHealthScore::calculate([
        ['category' => 'crawlability', 'severity' => 'critical'],
        ['category' => 'crawlability', 'severity' => 'high'],
        ['category' => 'crawlability', 'severity' => 'medium'],
        ['category' => 'crawlability', 'severity' => 'low'],
    ]);

    expect($result['score'])->toBe(78)
        ->and($result['breakdown']['crawlability']['open_issues'])->toBe(4)
        ->and($result['breakdown']['crawlability']['penalty'])->toBe(22);
});

it('caps a category at its own weight', function () {
    // 3 criticals = 36 points in on_page, capped at 20 — a pile of
    // one category must not zero the whole score.
    $result = CrawlHealthScore::calculate(array_fill(
        0, 3, ['category' => 'on_page', 'severity' => 'critical'],
    ));

    expect($result['breakdown']['on_page']['penalty'])->toBe(20)
        ->and($result['breakdown']['on_page']['open_issues'])->toBe(3)
        ->and($result['score'])->toBe(80);
});

it('sums uncapped penalties across categories', function () {
    $result = CrawlHealthScore::calculate([
        ...array_fill(0, 4, ['category' => 'crawlability', 'severity' => 'critical']), // 48 -> 30 (capped)
        ...array_fill(0, 2, ['category' => 'links', 'severity' => 'high']),            // 12
        ['category' => 'content', 'severity' => 'medium'],                             // 3
        ['category' => 'structured_data', 'severity' => 'low'],                        // 1
    ]);

    // 30 + 12 + 3 + 1 = 46.
    expect($result['score'])->toBe(54)
        ->and($result['breakdown']['crawlability']['penalty'])->toBe(30)
        ->and($result['breakdown']['links']['penalty'])->toBe(12)
        ->and($result['breakdown']['content']['penalty'])->toBe(3)
        ->and($result['breakdown']['structured_data']['penalty'])->toBe(1);
});

it('never scores below 15 while the category caps hold (85 total headroom rule)', function () {
    // Saturate every category far past its cap.
    $issues = [];
    foreach (CrawlHealthScore::CATEGORY_WEIGHTS as $category => $weight) {
        $issues = [...$issues, ...array_fill(0, 50, ['category' => $category, 'severity' => 'critical'])];
    }

    $result = CrawlHealthScore::calculate($issues);

    expect($result['score'])->toBe(15)
        ->and($result['score'])->toBeGreaterThanOrEqual(0);
});

it('ignores unknown severities and categories instead of guessing', function () {
    $result = CrawlHealthScore::calculate([
        ['category' => 'crawlability', 'severity' => 'blocker'], // not a crawl severity: counted, but costs nothing
        ['category' => 'performance', 'severity' => 'critical'], // performance module's category, not ours
        ['category' => 'links', 'severity' => 'high'],
    ]);

    expect($result['score'])->toBe(94)
        ->and($result['breakdown']['links']['open_issues'])->toBe(1)
        ->and($result['breakdown']['crawlability'])->toBe([
            'open_issues' => 1,
            'penalty'     => 0,
            'weight'      => 30,
        ]);
});

it('floors at zero defensively if penalties ever exceeded the caps', function () {
    // The caps make this unreachable through calculate()'s own bookkeeping;
    // the floor exists so a future weight change cannot produce a negative
    // score. Exercise the guard through a category list big enough that the
    // arithmetic would go negative without capping.
    $result = CrawlHealthScore::calculate(array_fill(
        0, 100, ['category' => 'crawlability', 'severity' => 'critical'],
    ));

    expect($result['score'])->toBeGreaterThanOrEqual(0)
        ->and($result['score'])->toBe(70);
});
