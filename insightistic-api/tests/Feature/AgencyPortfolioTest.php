<?php

use App\Models\AgencyClient;
use App\Models\CrawlIssue;
use App\Models\CrawlRun;
use App\Models\Organization;
use App\Models\SeoDaily;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;

/**
 * Phase 13 agency portfolio (doc 20): per-site health / uptime / search /
 * freshness rows plus the optional client grouping. Score expectations use
 * CrawlHealthScore's published weights (severity points: critical 12, high 6;
 * category caps: crawlability 30, on_page 20...).
 */

function portfolioSite(Organization $org, string $name): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => $name,
        'domain'            => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'.example.com',
        'connection_status' => 'connected',
        'timezone'          => 'UTC',
    ]);
}

function portfolioRun(Site $site): CrawlRun
{
    return CrawlRun::create(['site_id' => $site->id, 'status' => 'completed', 'trigger' => 'manual']);
}

function portfolioIssue(CrawlRun $run, string $severity, string $category = 'crawlability', ?string $code = null): CrawlIssue
{
    $code ??= $severity.'_'.$category.'_'.uniqid();

    return CrawlIssue::create([
        'site_id'      => $run->site_id,
        'run_id'       => $run->id,
        'issue_code'   => $code,
        'severity'     => $severity,
        'category'     => $category,
        'fingerprint'  => sha1($code.'|'.'/'.uniqid()),
        'target_url'   => '/page-'.uniqid(),
        'status'       => 'open',
    ]);
}

function portfolioMonitor(Site $site): UptimeMonitor
{
    return UptimeMonitor::create(['site_id' => $site->id, 'active' => true]);
}

function portfolioChecks(UptimeMonitor $monitor, Site $site, int $ups, int $downs = 0): void
{
    for ($i = 0; $i < $ups; $i++) {
        UptimeCheck::create([
            'monitor_id' => $monitor->id, 'site_id' => $site->id,
            'status' => 'up', 'checked_at' => now()->subMinutes($i + 1),
        ]);
    }
    for ($i = 0; $i < $downs; $i++) {
        UptimeCheck::create([
            'monitor_id' => $monitor->id, 'site_id' => $site->id,
            'status' => 'down', 'checked_at' => now()->subMinutes(100 + $i),
        ]);
    }
}

it('builds portfolio rows with health, uptime and search columns from seeded data', function () {
    [$user, $org] = makeOrgUser('portfolio-math@example.com');

    $healthy = portfolioSite($org, 'Healthy Site');
    portfolioRun($healthy); // crawled, zero open issues -> 100

    $troubled = portfolioSite($org, 'Troubled Site');
    $run = portfolioRun($troubled);
    portfolioIssue($run, 'critical'); // 12 penalty -> score 88, but a critical is critical regardless
    $troubled->update(['last_sync_at' => now()->subHours(3)]);

    $monitor = portfolioMonitor($troubled);
    portfolioChecks($monitor, $troubled, 9, 1); // 9 ups / 10 checks -> 90.0
    UptimeIncident::create([
        'monitor_id' => $monitor->id, 'site_id' => $troubled->id,
        'started_at' => now()->subHours(2), // no recovered_at -> still open
    ]);

    // Only the gsc rows inside 28 days may count: a bing row and an old
    // gsc row must both be invisible in the search column.
    SeoDaily::create(['site_id' => $troubled->id, 'source' => 'gsc', 'date' => now()->toDateString(), 'clicks' => 120, 'impressions' => 3000]);
    SeoDaily::create(['site_id' => $troubled->id, 'source' => 'bing', 'date' => now()->toDateString(), 'clicks' => 9999, 'impressions' => 99999]);
    SeoDaily::create(['site_id' => $troubled->id, 'source' => 'gsc', 'date' => now()->subDays(40)->toDateString(), 'clicks' => 500, 'impressions' => 8000]);

    $body = $this->actingAs($user)->getJson('/api/agency/portfolio')->assertOk()->json();

    $byName = collect($body['sites'])->keyBy('site_name');
    $troubledRow = $byName['Troubled Site'];
    $healthyRow = $byName['Healthy Site'];

    expect($body['summary']['sites'])->toBe(2)
        ->and($body['summary']['sites_with_critical'])->toBe(1)
        // Sorting: sites with critical issues first, then by name.
        ->and($body['sites'][0]['site_name'])->toBe('Troubled Site')
        ->and($troubledRow['health_score'])->toBe(88) // 100 - 12 critical severity points
        ->and($troubledRow['health_state'])->toBe('critical')
        ->and($troubledRow['critical_issues'])->toBe(1)
        ->and($troubledRow['has_crawl_data'])->toBeTrue()
        ->and($troubledRow['uptime']['availability_pct'])->toEqual(90.0) // JSON folds 90.0 to 90 (see AgencyOverviewTest)
        ->and($troubledRow['uptime']['open_incidents'])->toBe(1)
        ->and($troubledRow['search']['clicks'])->toBe(120)
        ->and($troubledRow['search']['impressions'])->toBe(3000)
        ->and($troubledRow['freshness']['last_sync_at'])->not->toBeNull()
        ->and($troubledRow['freshness']['data_as_of'])->not->toBeNull()
        ->and($healthyRow['health_score'])->toBe(100)
        ->and($healthyRow['health_state'])->toBe('healthy')
        ->and($healthyRow['critical_issues'])->toBe(0)
        ->and($healthyRow['uptime'])->toBeNull() // no monitor = honest null, not a fake 100%
        ->and($healthyRow['search'])->toBeNull()
        ->and($healthyRow['freshness']['data_as_of'])->not->toBeNull(); // crawled, so a crawl run date exists
});

it('reports an uncrawled site as unknown with a displayed score of 0', function () {
    [$user, $org] = makeOrgUser('portfolio-uncrawled@example.com');
    portfolioSite($org, 'Never Crawled');

    $row = $this->actingAs($user)->getJson('/api/agency/portfolio')->assertOk()->json('sites.0');

    expect($row['health_score'])->toBe(0)
        ->and($row['has_crawl_data'])->toBeFalse()
        ->and($row['health_state'])->toBe('unknown')
        ->and($row['freshness']['data_as_of'])->toBeNull(); // nothing ever measured
});

it('buckets sites into critical / warning / healthy / unknown health states', function () {
    [$user, $org] = makeOrgUser('portfolio-buckets@example.com');

    $critical = portfolioSite($org, 'Bucket Critical');
    portfolioIssue(portfolioRun($critical), 'critical');

    $warning = portfolioSite($org, 'Bucket Warning');
    $warningRun = portfolioRun($warning);
    for ($i = 0; $i < 4; $i++) {
        portfolioIssue($warningRun, 'high', 'crawlability', 'HIGH_'.$i); // 4 x 6 = 24 -> score 76, no criticals
    }

    $healthy = portfolioSite($org, 'Bucket Healthy');
    portfolioRun($healthy);

    portfolioSite($org, 'Bucket Unknown'); // never crawled

    $states = collect(
        $this->actingAs($user)->getJson('/api/agency/portfolio')->assertOk()->json('sites'),
    )->pluck('health_state', 'site_name');

    expect($states['Bucket Critical'])->toBe('critical')
        ->and($states['Bucket Warning'])->toBe('warning')
        ->and($states['Bucket Healthy'])->toBe('healthy')
        ->and($states['Bucket Unknown'])->toBe('unknown');

    // The health filter is applied post-fetch, per state.
    $criticalOnly = $this->actingAs($user)->getJson('/api/agency/portfolio?health=critical')->assertOk()->json('sites');
    $warningOnly = $this->actingAs($user)->getJson('/api/agency/portfolio?health=warning')->assertOk()->json('sites');
    $healthyOnly = $this->actingAs($user)->getJson('/api/agency/portfolio?health=healthy')->assertOk()->json('sites');

    expect(collect($criticalOnly)->pluck('site_name')->all())->toBe(['Bucket Critical'])
        ->and(collect($warningOnly)->pluck('site_name')->all())->toBe(['Bucket Warning'])
        ->and(collect($healthyOnly)->pluck('site_name')->all())->toBe(['Bucket Healthy']);
});

it('rejects an unknown health filter', function () {
    [$user, $org] = makeOrgUser('portfolio-bad-filter@example.com');

    $this->actingAs($user)
        ->getJson('/api/agency/portfolio?health=excellent')
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_health_filter');
});

it('filters the portfolio to one client and counts clients in the summary', function () {
    [$user, $org] = makeOrgUser('portfolio-client-filter@example.com');

    $acme = AgencyClient::create(['organization_id' => $org->id, 'name' => 'Acme']);
    $glob = AgencyClient::create(['organization_id' => $org->id, 'name' => 'Globex']);

    portfolioSite($org, 'Acme One')->update(['client_id' => $acme->id]);
    portfolioSite($org, 'Acme Two')->update(['client_id' => $acme->id]);
    portfolioSite($org, 'Globex One')->update(['client_id' => $glob->id]);
    portfolioSite($org, 'Unassigned One');

    $all = $this->actingAs($user)->getJson('/api/agency/portfolio')->assertOk()->json();
    expect($all['summary']['sites'])->toBe(4)
        ->and($all['summary']['clients'])->toBe(2);

    $acmeOnly = $this->actingAs($user)->getJson('/api/agency/portfolio?client_id='.$acme->id)->assertOk()->json();
    expect(collect($acmeOnly['sites'])->pluck('site_name')->sort()->values()->all())->toBe(['Acme One', 'Acme Two'])
        ->and($acmeOnly['sites'][0]['client_name'])->toBe('Acme')
        ->and($acmeOnly['summary']['sites'])->toBe(2)
        ->and($acmeOnly['summary']['clients'])->toBe(1);
});

it('stores, lists with site counts, and updates clients', function () {
    [$user, $org] = makeOrgUser('client-crud@example.com');
    $site = portfolioSite($org, 'Assigned Site');

    $created = $this->actingAs($user)->postJson('/api/agency/clients', [
        'name'          => 'Acme Corp',
        'contact_email' => 'billing@acme.test',
        'notes'         => 'Retainer starts in October',
    ])->assertCreated()->json();

    expect($created['name'])->toBe('Acme Corp')
        ->and($created['contact_email'])->toBe('billing@acme.test');

    // index includes the assigned-site count
    $site->update(['client_id' => $created['id']]);
    $listed = $this->actingAs($user)->getJson('/api/agency/clients')->assertOk()->json();
    expect($listed['clients'])->toHaveCount(1)
        ->and($listed['clients'][0]['sites_count'])->toBe(1);

    $this->actingAs($user)
        ->patchJson("/api/agency/clients/{$created['id']}", ['name' => 'Acme Renamed'])
        ->assertOk()
        ->assertJsonPath('name', 'Acme Renamed');

    // Validation: name required, email format, notes length.
    $this->actingAs($user)->postJson('/api/agency/clients', ['contact_email' => 'not-an-email'])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/agency/clients', ['name' => 'X', 'notes' => str_repeat('a', 501)])->assertStatus(422);
});

it('keeps another organization\'s client invisible (404, not 403)', function () {
    [$userA] = makeOrgUser('client-tenancy-a@example.com');
    [$userB, $orgB] = makeOrgUser('client-tenancy-b@example.com');
    $foreign = AgencyClient::create(['organization_id' => $orgB->id, 'name' => 'B Client']);

    $this->actingAs($userA)->getJson('/api/agency/portfolio?client_id='.$foreign->id)->assertNotFound();
    $this->actingAs($userA)->patchJson("/api/agency/clients/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();
    $this->actingAs($userA)->deleteJson("/api/agency/clients/{$foreign->id}")->assertNotFound();
    $this->actingAs($userA)->postJson("/api/agency/clients/{$foreign->id}/sites", ['site_ids' => []])->assertNotFound();

    expect($foreign->refresh()->name)->toBe('B Client'); // untouched
});

it('lets analysts read the portfolio but not write clients', function () {
    [$owner, $org] = makeOrgUser('roles-owner@example.com');
    [$analyst] = makeOrgUser('roles-analyst@example.com', null, 'analyst');
    $org->users()->attach($analyst->id, ['role' => 'analyst']);

    $this->actingAs($analyst)->getJson('/api/agency/portfolio')->assertOk();
    $this->actingAs($analyst)->getJson('/api/agency/clients')->assertOk();

    $this->actingAs($analyst)->postJson('/api/agency/clients', ['name' => 'Nope'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'insufficient_role');

    $this->actingAs($owner)->postJson('/api/agency/clients', ['name' => 'Owner Client'])->assertCreated();
});

it('assigns and unassigns sites to a client', function () {
    [$user, $org] = makeOrgUser('assign@example.com');
    $client = AgencyClient::create(['organization_id' => $org->id, 'name' => 'Acme']);
    $one = portfolioSite($org, 'One');
    $two = portfolioSite($org, 'Two');

    $this->actingAs($user)
        ->postJson("/api/agency/clients/{$client->id}/sites", ['site_ids' => [$one->id, $two->id]])
        ->assertOk();

    expect($one->refresh()->client_id)->toBe($client->id)
        ->and($two->refresh()->client_id)->toBe($client->id);

    // Sync semantics: shrinking the list unassigns the dropped site.
    $this->actingAs($user)
        ->postJson("/api/agency/clients/{$client->id}/sites", ['site_ids' => [$one->id]])
        ->assertOk();

    expect($one->refresh()->client_id)->toBe($client->id)
        ->and($two->refresh()->client_id)->toBeNull();
});

it('rejects site ids that belong to another organization on assignment', function () {
    [$userA, $orgA] = makeOrgUser('assign-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('assign-iso-b@example.com');
    $foreignSite = portfolioSite($orgB, 'Foreign');
    $client = AgencyClient::create(['organization_id' => $orgA->id, 'name' => 'A']);

    $this->actingAs($userA)
        ->postJson("/api/agency/clients/{$client->id}/sites", ['site_ids' => [$foreignSite->id]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_site_ids');

    expect($foreignSite->refresh()->client_id)->toBeNull(); // nothing written cross-tenant
});

it('unassigns its sites when a client is deleted', function () {
    [$user, $org] = makeOrgUser('delete-client@example.com');
    $client = AgencyClient::create(['organization_id' => $org->id, 'name' => 'Doomed']);
    $site = portfolioSite($org, 'Soon Orphaned');
    $site->update(['client_id' => $client->id]);

    $this->actingAs($user)->deleteJson("/api/agency/clients/{$client->id}")->assertNoContent();

    expect($site->refresh()->client_id)->toBeNull() // unassigned, NOT deleted
        ->and(AgencyClient::query()->where('organization_id', $org->id)->count())->toBe(0);

    $body = $this->actingAs($user)->getJson('/api/agency/portfolio')->assertOk()->json();
    expect($body['sites'][0]['client_id'])->toBeNull()
        ->and($body['sites'][0]['client_name'])->toBeNull()
        ->and($body['summary']['clients'])->toBe(0);
});
