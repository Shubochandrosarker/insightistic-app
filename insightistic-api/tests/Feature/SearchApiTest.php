<?php

use App\Jobs\BackfillGscHistory;
use App\Models\DataSourceCredential;
use App\Models\SeoBackfillState;
use App\Models\SeoQueryDaily;
use App\Models\Site;
use App\Models\SiteUserAccess;
use Illuminate\Support\Facades\Queue;

/**
 * Search Intelligence API surface (Phase 5, roadmap 10-GOOGLE-SEARCH-CONSOLE):
 * tenant isolation, role gating, the GSC backfill dispatch path, backfill
 * status, and the bounded CSV export.
 */

/** Site helper — uniquely named: IntegrationsTest's makeSiteFor already exists process-wide. */
function searchApiSite($org, ?string $domain = null): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => 'Search Test Store',
        'domain'          => $domain ?? ('search-' . uniqid() . '.example.com'),
    ]);
}

function searchApiGscCredential(Site $site): DataSourceCredential
{
    return DataSourceCredential::withoutGlobalScope('organization')->create([
        'organization_id'  => $site->organization_id,
        'site_id'          => $site->id,
        'source'           => 'gsc',
        'provider'         => 'google',
        'access_token'     => 'at-search',
        'refresh_token'    => 'rt-search',
        'token_expires_at' => now()->addHour(),
        'resource_json'    => ['id' => 'sc-domain:example.com', 'name' => 'example.com'],
    ]);
}

function searchApiSeedQuery(Site $site, string $query, int $clicks = 10, int $impressions = 500, float $position = 6.5): void
{
    SeoQueryDaily::create([
        'site_id'     => $site->id,
        'date'        => now()->subDays(2)->toDateString(),
        'query_hash'  => sha1($query),
        'query'       => $query,
        'clicks'      => $clicks,
        'impressions' => $impressions,
        'ctr'         => round($clicks / $impressions, 4),
        'position'    => $position,
    ]);
}

it('hides other organizations search endpoints behind a 404', function () {
    [$userA] = makeOrgUser('search-tenant-a@example.com');
    [, $orgB] = makeOrgUser('search-tenant-b@example.com');
    $siteB = searchApiSite($orgB);

    $this->actingAs($userA)
        ->getJson("/api/sites/{$siteB->id}/search/queries")
        ->assertNotFound();
});

it('lets a granted client viewer read search data but not backfill', function () {
    [$viewer, $org] = makeOrgUser('search-viewer@example.com', null, 'client_viewer');
    $site = searchApiSite($org);
    SiteUserAccess::create(['site_id' => $site->id, 'user_id' => $viewer->id, 'role' => 'viewer']);

    // Reads are open to anyone with site access (mirrors the analytics routes).
    $this->actingAs($viewer)
        ->getJson("/api/sites/{$site->id}/search/queries")
        ->assertOk()
        ->assertJsonPath('meta.total_queries', 0);

    // Backfill is a queued heavy action — owner/admin only, like every
    // other sync/run endpoint.
    $this->actingAs($viewer)
        ->postJson("/api/sites/{$site->id}/search/backfill")
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_role');
});

it('validates explorer parameters and falls back for out-of-range windows', function () {
    [$user, $org] = makeOrgUser('search-validate@example.com');
    $site = searchApiSite($org);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/search/queries?sort=bogus")
        ->assertStatus(422);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/search/queries?per_page=500")
        ->assertStatus(422);

    // window=45 is not a valid preset; the endpoint falls back to 28 days
    // rather than erroring (matches the analytics surfaces' tolerant ranges).
    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/search/queries?window=45")
        ->assertOk()
        ->assertJsonPath('meta.total_queries', 0);
});

it('refuses a backfill without a gsc connection and dispatches with one', function () {
    [$owner, $org] = makeOrgUser('search-backfill@example.com');
    $site = searchApiSite($org);
    Queue::fake();

    // No GSC credential yet.
    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/search/backfill")
        ->assertStatus(422)
        ->assertJsonPath('code', 'gsc_not_connected');

    Queue::assertNothingPushed();

    searchApiGscCredential($site);

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/search/backfill")
        ->assertOk()
        ->assertJsonPath('dispatched', true)
        ->assertJsonPath('dimensions', ['queries', 'pages', 'query_page']);

    Queue::assertPushed(BackfillGscHistory::class);
});

it('reports per dimension backfill state', function () {
    [$user, $org] = makeOrgUser('search-status@example.com');
    $site = searchApiSite($org);

    SeoBackfillState::create([
        'site_id'      => $site->id,
        'dimension'    => 'queries',
        'status'       => 'complete',
        'covered_from' => '2025-05-01',
        'covered_to'   => '2026-06-18',
        'attempts'     => 2,
        'last_error'   => null,
    ]);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/search/backfill-status")
        ->assertOk()
        ->assertJsonPath('states.0.dimension', 'queries')
        ->assertJsonPath('states.0.status', 'complete')
        ->assertJsonPath('states.0.covered_from', '2025-05-01')
        ->assertJsonPath('states.0.covered_to', '2026-06-18')
        ->assertJsonPath('states.0.attempts', 2);
});

it('returns all opportunity reports and honours a single type filter', function () {
    [$user, $org] = makeOrgUser('search-opps@example.com');
    $site = searchApiSite($org);
    searchApiSeedQuery($site, 'opportunity query');

    $all = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/search/opportunities")
        ->assertOk();

    expect($all->json('opportunities'))->toHaveKeys([
        'winners', 'losers', 'striking_distance', 'ctr_opportunities',
        'content_decay', 'cannibalization',
    ])
        ->and($all->json('window.from'))->toBeString();

    $single = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/search/opportunities?type=striking_distance")
        ->assertOk()
        ->assertJsonCount(1, 'opportunities')
        ->assertJsonPath('opportunities.striking_distance.0.query', 'opportunity query');
});

it('exports the queries report as csv', function () {
    [$user, $org] = makeOrgUser('search-export@example.com');
    $site = searchApiSite($org);
    searchApiSeedQuery($site, 'csv export query');

    $response = $this->actingAs($user)
        ->get("/api/sites/{$site->id}/search/export.csv?type=queries")
        ->assertOk()
        // Symfony appends the charset to the streamed header.
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Query')
        ->and($csv)->toContain('csv export query')
        ->and($csv)->toContain('Clicks');

    // Unknown export type is rejected, not silently defaulted.
    $this->actingAs($user)
        ->get("/api/sites/{$site->id}/search/export.csv?type=nope")
        ->assertStatus(422);
});
