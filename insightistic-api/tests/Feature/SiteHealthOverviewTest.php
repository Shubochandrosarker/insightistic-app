<?php

use App\Models\CrawlIssue;
use App\Models\CrawlRun;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;

/**
 * Overview site health (spec §19): the health shown on Overview is a REAL
 * composite (crawl issues + SSL + uptime) — never the WordPress connector
 * status — and reports an honest "no audit yet" instead of a fake green.
 */
function makeAuditedSite($org, array $issues = []): Site
{
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'H', 'domain' => 'h.example.com',
        'normalized_domain' => 'h.example.com', 'platform' => 'generic', 'status' => 'active',
    ]);
    $run = CrawlRun::create(['site_id' => $site->id, 'status' => 'completed', 'max_pages' => 10]);
    foreach ($issues as $i => [$category, $severity]) {
        $code = 'test_' . $severity . '_' . $i;
        CrawlIssue::create([
            'site_id' => $site->id, 'run_id' => $run->id,
            'category' => $category, 'severity' => $severity,
            'issue_code' => $code, 'target_url' => 'https://h.example.com/x' . $i,
            'fingerprint' => md5($code . '|https://h.example.com/x' . $i),
            'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    return $site;
}

it('computes health from crawl issues, not connector status', function () {
    [$user, $org] = makeOrgUser('sh-crawl@example.com');
    $site = makeAuditedSite($org, [
        ['crawlability', 'critical'],
        ['on_page', 'medium'],
    ]);

    $response = $this->actingAs($user)->getJson("/api/sites/{$site->id}/health")->assertOk();

    $health = $response->json('health');
    // 100 - 12 (critical crawlability) - 3 (medium on_page) = 85
    expect($health['score'])->toBe(85)
        ->and($health['state'])->toBe('ok')
        ->and($health['components']['crawl']['available'])->toBeTrue()
        ->and($health['components']['crawl']['open_issues'])->toBe(2)
        ->and($health['sources']['health'])->toBe('site_audit');
});

it('penalizes expiring SSL and low availability on top of crawl issues', function () {
    [$user, $org] = makeOrgUser('sh-ssl@example.com');
    $site = makeAuditedSite($org); // clean crawl → 100

    UptimeMonitor::create([
        'site_id' => $site->id, 'url' => 'https://h.example.com', 'method' => 'GET',
        'expected_status' => 200, 'interval_minutes' => 5, 'active' => true,
        'ssl_expires_at' => now()->addDays(10), 'ssl_checked_at' => now(),
        'last_checked_at' => now(),
    ]);
    $monitor = UptimeMonitor::where('site_id', $site->id)->first();
    $mk = fn (string $status) => UptimeCheck::create([
        'monitor_id' => $monitor->id,
        'site_id'    => $site->id,
        'status'     => $status,
        'checked_at' => now(),
    ]);
    // force availability to 75% (6 up / 2 down)
    $mk('down'); $mk('down');
    for ($i = 0; $i < 6; $i++) { $mk('up'); }

    $health = $this->actingAs($user)->getJson("/api/sites/{$site->id}/health")->assertOk()->json('health');

    // 100 - 4 (SSL <30d) - 8 (availability <95% ... 75%) = 88
    expect($health['components']['ssl']['state'])->toBe('expiring')
        ->and((float) $health['components']['uptime']['availability'])->toBe(75.0)
        ->and($health['score'])->toBe(88);
});

it('reports no_audit honestly instead of a fake healthy score', function () {
    [$user, $org] = makeOrgUser('sh-none@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'N', 'domain' => 'n.example.com',
        'normalized_domain' => 'n.example.com', 'platform' => 'generic',
    ]);

    $health = $this->actingAs($user)->getJson("/api/sites/{$site->id}/health")->assertOk()->json('health');

    expect($health['state'])->toBe('no_audit')
        ->and($health['score'])->toBeNull()
        ->and($health['sources']['health'])->toBe('none');
});
