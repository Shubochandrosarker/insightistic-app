<?php

use App\Models\SeoDaily;
use App\Models\SeoQuerySnapshot;
use App\Models\Site;

/**
 * Multi-source SEO reads (Phase 6, roadmap 11-BING-WEBMASTER-INDEXNOW):
 * the seo overview honours ?source=gsc|bing|combined, volumes combine
 * exactly across providers, but CTR/position are never blended when two
 * providers have data, and the Bing weekly endpoint only ever shows Bing
 * rows. Tenant isolation applies to every new read.
 */

const SEO_WINDOW = ['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-09-30'];

function makeSeoSite(\App\Models\Organization $org, string $domain = 'store.example.com'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Test Store',
        'domain'          => $domain,
    ]);
}

function seedSeoSources(Site $site): void
{
    // Search Console: two days, positions present.
    SeoDaily::create(['site_id' => $site->id, 'source' => 'gsc', 'date' => '2026-08-10', 'clicks' => 30, 'impressions' => 1000, 'ctr' => 0.03, 'avg_position' => 10.0]);
    SeoDaily::create(['site_id' => $site->id, 'source' => 'gsc', 'date' => '2026-08-11', 'clicks' => 50, 'impressions' => 1000, 'ctr' => 0.05, 'avg_position' => 12.0]);

    // Bing: one overlapping day plus one exclusive day; Bing's daily pull
    // carries no CTR/position, so those stay null.
    SeoDaily::create(['site_id' => $site->id, 'source' => 'bing', 'date' => '2026-08-11', 'clicks' => 20, 'impressions' => 500]);
    SeoDaily::create(['site_id' => $site->id, 'source' => 'bing', 'date' => '2026-09-01', 'clicks' => 10, 'impressions' => 500]);
}

it('defaults the seo overview to gsc rows only', function () {
    [$user, $org] = makeOrgUser('seo-gsc@example.com');
    $site = makeSeoSite($org);
    seedSeoSources($site);

    $json = $this->actingAs($user)
        ->getJson('/api/sites/' . $site->id . '/analytics/seo/overview?' . http_build_query(SEO_WINDOW))
        ->assertOk()
        ->assertJsonPath('source', 'gsc');

    $json->assertJsonPath('metrics.clicks', 80)
        ->assertJsonPath('metrics.impressions', 2000)
        ->assertJsonPath('metrics.ctr', 4) // 80/2000; JSON strips the zero fraction
        ->assertJsonPath('metrics.avg_position', 11) // avg(10,12); JSON strips the zero fraction
        ->assertJsonPath('has_data', true)
        ->assertJsonPath('position_sources', null)
        ->assertJsonCount(2, 'series');
});

it('filters the seo overview to bing rows on demand', function () {
    [$user, $org] = makeOrgUser('seo-bing@example.com');
    $site = makeSeoSite($org);
    seedSeoSources($site);

    $this->actingAs($user)
        ->getJson('/api/sites/' . $site->id . '/analytics/seo/overview?source=bing&' . http_build_query(SEO_WINDOW))
        ->assertOk()
        ->assertJsonPath('source', 'bing')
        ->assertJsonPath('metrics.clicks', 30)        // 20 + 10
        ->assertJsonPath('metrics.impressions', 1000) // 500 + 500
        ->assertJsonPath('metrics.ctr', 3)            // ratio of bing's own sums
        ->assertJsonPath('metrics.avg_position', null); // bing has no position data
});

it('sums volumes but never blends rates in the combined view', function () {
    [$user, $org] = makeOrgUser('seo-combined@example.com');
    $site = makeSeoSite($org);
    seedSeoSources($site);

    $json = $this->actingAs($user)
        ->getJson('/api/sites/' . $site->id . '/analytics/seo/overview?source=combined&' . http_build_query(SEO_WINDOW))
        ->assertOk()
        ->assertJsonPath('source', 'combined');

    $json->assertJsonPath('metrics.clicks', 110)       // 80 gsc + 30 bing
        ->assertJsonPath('metrics.impressions', 3000)  // 2000 + 1000
        // Both providers have rows in-window: a blended CTR/position would be
        // arithmetic across two different search engines — refused outright.
        ->assertJsonPath('metrics.ctr', null)
        ->assertJsonPath('metrics.avg_position', null)
        ->assertJsonPath('position_sources', 'bing+gsc')
        ->assertJsonCount(3, 'series');

    // Per-day series follows the same rule: shared days lose CTR/position,
    // single-provider days keep theirs.
    $series = $json->json('series');

    expect($series[0]['date'])->toBe('2026-08-10') // gsc only
        ->and($series[0]['ctr'])->toBe(0.03)
        ->and($series[0]['avg_position'])->toEqual(10.0) // JSON round-trip: 10.0 → 10
        ->and($series[1]['date'])->toBe('2026-08-11') // both providers
        ->and($series[1]['clicks'])->toBe(70)
        ->and($series[1]['impressions'])->toBe(1500)
        ->and($series[1]['ctr'])->toBeNull()
        ->and($series[1]['avg_position'])->toBeNull()
        ->and($series[2]['date'])->toBe('2026-09-01') // bing only
        ->and($series[2]['ctr'])->toBe(0.02)          // computed from bing's own volumes
        ->and($series[2]['avg_position'])->toBeNull();
});

it('rejects an unknown source filter', function () {
    [$user, $org] = makeOrgUser('seo-badsource@example.com');
    $site = makeSeoSite($org);
    seedSeoSources($site);

    $this->actingAs($user)
        ->getJson('/api/sites/' . $site->id . '/analytics/seo/overview?source=ahrefs')
        ->assertStatus(422);
});

it('serves bing weekly query buckets with the documented shape', function () {
    [$user, $org] = makeOrgUser('seo-bingweekly@example.com');
    $site = makeSeoSite($org);

    $recent = now()->subDays(10)->toDateString();

    SeoQuerySnapshot::create(['site_id' => $site->id, 'source' => 'bing', 'period_start' => $recent, 'period_end' => $recent, 'query' => 'hats', 'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'avg_click_position' => 3.5, 'avg_impression_position' => 8.5, 'created_at' => now()]);
    SeoQuerySnapshot::create(['site_id' => $site->id, 'source' => 'bing', 'period_start' => $recent, 'period_end' => $recent, 'query' => 'caps', 'clicks' => 2, 'impressions' => 300, 'ctr' => 0.0067, 'avg_click_position' => 6.0, 'avg_impression_position' => 12.0, 'created_at' => now()]);
    // A GSC snapshot in the same table must never leak into the bing view...
    SeoQuerySnapshot::create(['site_id' => $site->id, 'source' => 'gsc', 'period_start' => '2026-08-01', 'period_end' => '2026-09-30', 'query' => 'gsc only', 'clicks' => 9, 'impressions' => 999, 'ctr' => 0.009, 'position' => 7.7, 'created_at' => now()]);
    // ...and old bing buckets fall outside a tight window.
    SeoQuerySnapshot::create(['site_id' => $site->id, 'source' => 'bing', 'period_start' => now()->subDays(200)->toDateString(), 'period_end' => now()->subDays(200)->toDateString(), 'query' => 'ancient', 'clicks' => 50, 'impressions' => 5000, 'created_at' => now()]);

    $weekly = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/analytics/seo/bing?window=90")
        ->assertOk()
        ->assertJsonPath('window_days', 90)
        ->json('weekly');

    expect($weekly)->toHaveCount(2)
        ->and($weekly[0]['query'])->toBe('caps') // top by impressions
        ->and($weekly[0]['impressions'])->toBe(300)
        ->and($weekly[0]['week_end'])->toBe($recent)
        ->and($weekly[0]['avg_click_position'])->toEqual(6.0)      // JSON round-trip: 6.0 → 6
        ->and($weekly[0]['avg_impression_position'])->toEqual(12.0)
        ->and($weekly[1]['query'])->toBe('hats')
        ->and($weekly[1]['avg_click_position'])->toBe(3.5);

    // Default window (90d) already excludes the old bucket; assert the
    // filter works by widening to cover it.
    $wide = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/analytics/seo/bing?window=365")
        ->assertOk()
        ->json('weekly');

    expect(count($wide))->toBe(3);
});

it('keeps bing weekly buckets out of the gsc queries endpoint', function () {
    [$user, $org] = makeOrgUser('seo-queries@example.com');
    $site = makeSeoSite($org);
    seedSeoSources($site);

    $recent = now()->subDays(10)->toDateString();
    SeoQuerySnapshot::create(['site_id' => $site->id, 'source' => 'bing', 'period_start' => $recent, 'period_end' => $recent, 'query' => 'bing weekly', 'clicks' => 4, 'impressions' => 800, 'created_at' => now()]);
    SeoQuerySnapshot::create(['site_id' => $site->id, 'source' => 'gsc', 'period_start' => '2026-08-01', 'period_end' => '2026-09-30', 'query' => 'gsc query', 'clicks' => 9, 'impressions' => 999, 'ctr' => 0.009, 'position' => 7.7, 'created_at' => now()]);

    $queries = $this->actingAs($user)
        ->getJson('/api/sites/' . $site->id . '/analytics/seo/queries?' . http_build_query(SEO_WINDOW))
        ->assertOk()
        ->json('queries');

    expect($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toBe('gsc query');
});

it('hides another organization site from the seo endpoints', function () {
    [$userA] = makeOrgUser('seo-tenant-a@example.com');
    [, $orgB] = makeOrgUser('seo-tenant-b@example.com');
    $siteB = makeSeoSite($orgB, 'other.example.com');
    seedSeoSources($siteB);

    // A site in another org must not exist for this user — 404, not 403.
    $this->actingAs($userA)
        ->getJson('/api/sites/' . $siteB->id . '/analytics/seo/overview?' . http_build_query(SEO_WINDOW))
        ->assertNotFound();

    $this->actingAs($userA)
        ->getJson("/api/sites/{$siteB->id}/analytics/seo/bing")
        ->assertNotFound();

    expect(SeoDaily::count())->toBe(4); // nothing was written or leaked
});
