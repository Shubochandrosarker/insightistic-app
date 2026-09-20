<?php

use App\Models\CloudflareBotDaily;
use App\Models\CloudflareDaily;
use App\Models\Site;
use App\Models\TrafficDaily;
use App\Services\Integrations\IntegrationFailure;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Edge Intelligence (Phase 9, roadmap 15): the nightly sync's
 * GraphQL fetch/normalize into cloudflare_daily + cloudflare_bot_daily
 * (idempotent), plan-based degradation of the gated datasets, and the
 * GET sites/{site}/cloudflare endpoint that reads those rows.
 *
 * The GraphQL endpoint is Http::faked. One full manager sync issues FOUR
 * requests to the same URL, in this order (hence the sequences):
 *   1. CloudflareTrafficService::fetch — the pre-existing traffic_daily pull
 *   2. CloudflareAnalytics — httpRequests1dGroups (primary dataset)
 *   3. CloudflareAnalytics — firewallEventsAdaptiveGroups (plan-gated)
 *   4. CloudflareAnalytics — httpRequestsAdaptiveGroups bots (plan-gated)
 */

function makeCfEdgeSite(\App\Models\Organization $org, bool $connected = true): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id'      => $org->id,
        'name'                 => 'Edge Store',
        'domain'               => 'edge.example.com',
        'cloudflare_zone_id'   => $connected ? 'zone123abc' : null,
        'cloudflare_api_token' => $connected ? str_repeat('c', 40) : null,
    ]);
}

/** A GraphQL body wrapping one zone document. */
function cfEdgeZone(array $zone): array
{
    return ['data' => ['viewer' => ['zones' => [$zone]]]];
}

function cfEdgeError(string $message): array
{
    return ['errors' => [['message' => $message]]];
}

/**
 * Four-push sequence: legacy traffic pull, then edge 1dGroups, firewall and
 * bots. $times repeats the whole round (one manager sync = 4 requests, so a
 * replayed sync needs times=2).
 */
function fakeCfEdgeSync(array $legacy, array $daily, array $firewall, array $bots, int $times = 1): void
{
    $sequence = Http::sequence()
        ->whenEmpty(Http::response(cfEdgeError('no stubbed response'), 500));

    for ($i = 0; $i < $times; $i++) {
        $sequence = $sequence
            ->push(cfEdgeZone($legacy))
            ->push(cfEdgeZone($daily))
            ->push(cfEdgeZone($firewall))
            ->push(cfEdgeZone($bots));
    }

    Http::fake([
        'api.cloudflare.com/client/v4/graphql' => $sequence,
    ]);
}

/** Two-day httpRequests1dGroups document with hand-computable totals. */
function cfEdgeDailyDoc(string $dayA, string $dayB): array
{
    return [
        'httpRequests1dGroups' => [
            [
                'dimensions' => ['date' => $dayA],
                'sum'        => [
                    'requests'           => 1000,
                    'bytes'              => 2000000000,
                    'cachedRequests'     => 600,
                    'cachedBytes'        => 1200000000,
                    'encryptedRequests'  => 900,
                    'pageViews'          => 300,
                    'threats'            => 12,
                    'countryMap'         => [
                        ['clientCountryName' => 'US', 'requests' => 700],
                        ['clientCountryName' => 'DE', 'requests' => 200],
                        ['clientCountryName' => 'FR', 'requests' => 100],
                    ],
                    'responseStatusMap'  => [
                        ['edgeResponseStatus' => '200', 'requests' => 700],
                        ['edgeResponseStatus' => '301', 'requests' => 200],
                        ['edgeResponseStatus' => '404', 'requests' => 60],
                        ['edgeResponseStatus' => '500', 'requests' => 40],
                    ],
                ],
            ],
            [
                'dimensions' => ['date' => $dayB],
                'sum'        => [
                    'requests'           => 500,
                    'bytes'              => 500000000,
                    'cachedRequests'     => 100,
                    'cachedBytes'        => 100000000,
                    'encryptedRequests'  => 450,
                    'pageViews'          => 120,
                    'threats'            => 3,
                    'countryMap'         => [
                        ['clientCountryName' => 'US', 'requests' => 400],
                    ],
                    'responseStatusMap'  => [
                        ['edgeResponseStatus' => '200', 'requests' => 480],
                        ['edgeResponseStatus' => '503', 'requests' => 20],
                    ],
                ],
            ],
        ],
    ];
}

it('syncs cloudflare edge daily aggregates from graphql and classifies bots', function () {
    $dayA = now()->subDay()->toDateString();
    $dayB = now()->subDays(2)->toDateString();

    [, $org] = makeOrgUser('edge-sync@example.com');
    $site = makeCfEdgeSite($org);

    // Request 1 keeps the pre-existing traffic_daily pull alive (one day).
    $legacy = [
        'httpRequests1dGroups' => [
            [
                'dimensions' => ['date' => $dayA],
                'sum'        => ['pageViews' => 300],
                'uniq'       => ['uniques' => 210],
            ],
        ],
        'firewallEventsAdaptiveGroups' => [],
    ];

    $firewall = [
        'firewallEventsAdaptiveGroups' => [
            ['dimensions' => ['date' => $dayA, 'action' => 'block'], 'count' => 7],
            ['dimensions' => ['date' => $dayA, 'action' => 'challenge'], 'count' => 5],
            ['dimensions' => ['date' => $dayB, 'action' => 'block'], 'count' => 2],
        ],
    ];

    $bots = [
        'httpRequestsAdaptiveGroups' => [
            // UA strings stay under the bot_name column's 64 chars.
            ['dimensions' => ['date' => $dayA, 'clientRequestUserAgent' => 'Mozilla/5.0 (compatible; GPTBot/1.1)'], 'count' => 40],
            ['dimensions' => ['date' => $dayA, 'clientRequestUserAgent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'], 'count' => 120],
            ['dimensions' => ['date' => $dayB, 'clientRequestUserAgent' => 'Mozilla/5.0 (compatible; AhrefsBot/7.0)'], 'count' => 15],
            // Unrecognized UA: not bot intelligence we claim to know, dropped.
            ['dimensions' => ['date' => $dayB, 'clientRequestUserAgent' => 'SomeRandomFetcher/1.0'], 'count' => 999],
        ],
    ];

    fakeCfEdgeSync($legacy, cfEdgeDailyDoc($dayA, $dayB), $firewall, $bots);

    $result = app(IntegrationManager::class)->sync($site, 'cloudflare');

    // Pre-existing behavior preserved: traffic_daily under source cloudflare.
    expect(TrafficDaily::where('site_id', $site->id)->where('source', 'cloudflare')->count())->toBe(1)
        ->and((int) TrafficDaily::where('site_id', $site->id)->where('source', 'cloudflare')->value('users'))->toBe(210);

    // Edge counters ride along on the sync result.
    expect($result['edge_days'])->toBe(2)
        ->and($result['edge_latest_date'])->toBe($dayA)
        ->and($result['edge_bots_available'])->toBeTrue()
        ->and($result['edge_flags'])->toBe([]);

    $daily = CloudflareDaily::where('site_id', $site->id)->orderBy('date')->get();
    expect($daily)->toHaveCount(2);

    // $row->date is a Carbon (model cast), so match on the string form.
    $rowA = $daily->first(fn ($r) => $r->date->toDateString() === $dayA);
    expect((int) $rowA->requests)->toBe(1000)
        ->and((int) $rowA->bytes)->toBe(2000000000)
        ->and((int) $rowA->cached_requests)->toBe(600)
        ->and((int) $rowA->client_4xx)->toBe(60)   // 404 only
        ->and((int) $rowA->client_5xx)->toBe(40)   // 500 only
        ->and($rowA->countries_json)->toBe(['US' => 700, 'DE' => 200, 'FR' => 100])
        ->and($rowA->status_breakdown_json)->toBe(['200' => 700, '301' => 200, '404' => 60, '500' => 40])
        ->and($rowA->firewall_json)->toBe(['block' => 7, 'challenge' => 5]);

    $rowB = $daily->first(fn ($r) => $r->date->toDateString() === $dayB);
    expect((int) $rowB->client_4xx)->toBe(0)
        ->and((int) $rowB->client_5xx)->toBe(20)
        ->and($rowB->firewall_json)->toBe(['block' => 2]);

    // Bots: classified per roadmap 15, unknown UAs never stored.
    $botRows = CloudflareBotDaily::where('site_id', $site->id)->get();
    expect($botRows)->toHaveCount(3);

    $gpt = $botRows->firstWhere('bot_name', 'Mozilla/5.0 (compatible; GPTBot/1.1)');
    expect($gpt->classification)->toBe('ai_crawler')
        ->and((int) $gpt->requests)->toBe(40);

    $google = $botRows->firstWhere('bot_name', 'Mozilla/5.0 (compatible; Googlebot/2.1)');
    expect($google->classification)->toBe('search_bot')
        ->and((int) $google->requests)->toBe(120);

    $ahrefs = $botRows->firstWhere('bot_name', 'Mozilla/5.0 (compatible; AhrefsBot/7.0)');
    expect($ahrefs->classification)->toBe('other');

    expect($botRows->pluck('bot_name'))->not->toContain('SomeRandomFetcher/1.0');
});

it('stores edge rows idempotently when the nightly sync replays', function () {
    $dayA = now()->subDay()->toDateString();

    [, $org] = makeOrgUser('edge-replay@example.com');
    $site = makeCfEdgeSite($org);

    fakeCfEdgeSync(
        ['httpRequests1dGroups' => [], 'firewallEventsAdaptiveGroups' => []],
        cfEdgeDailyDoc($dayA, now()->subDays(2)->toDateString()),
        ['firewallEventsAdaptiveGroups' => []],
        ['httpRequestsAdaptiveGroups' => []],
        times: 2, // replay = 4 more GraphQL requests
    );

    app(IntegrationManager::class)->sync($site, 'cloudflare');
    app(IntegrationManager::class)->sync($site, 'cloudflare');

    expect(CloudflareDaily::where('site_id', $site->id)->count())->toBe(2)
        ->and(CloudflareBotDaily::where('site_id', $site->id)->count())->toBe(0);

    // Values updated in place, not duplicated (dayA requests still 1000).
    expect((int) CloudflareDaily::where('site_id', $site->id)->where('date', $dayA)->value('requests'))->toBe(1000);
});

it('degrades plan-gated firewall and bot datasets without failing the sync', function () {
    $dayA = now()->subDay()->toDateString();

    [, $org] = makeOrgUser('edge-degrade@example.com');
    $site = makeCfEdgeSite($org);

    Http::fake([
        'api.cloudflare.com/client/v4/graphql' => Http::sequence()
            ->push(cfEdgeZone(['httpRequests1dGroups' => [], 'firewallEventsAdaptiveGroups' => []])) // legacy pull
            ->push(cfEdgeZone(cfEdgeDailyDoc($dayA, now()->subDays(2)->toDateString())))             // primary dataset OK
            ->push(cfEdgeError("Unknown argument 'limit' on field 'firewallEventsAdaptiveGroups' of field 'zones'.")) // plan-gated
            ->push(cfEdgeError("Unknown argument 'httpRequestsAdaptiveGroups' on field 'zones'."))    // plan-gated
            ->push(cfEdgeError("Unknown argument 'httpRequestsAdaptiveGroups' on field 'zones'."))    // for the direct fetchBots probe below
            ->whenEmpty(Http::response(cfEdgeError('no stubbed response'), 500)),
    ]);

    $result = app(IntegrationManager::class)->sync($site, 'cloudflare');

    // No exception; primary rows stored, gated sections flagged.
    expect($result['edge_days'])->toBe(2)
        ->and($result['edge_bots_available'])->toBeFalse()
        ->and($result['edge_flags'])->toBe(['firewall_unavailable', 'bot_analytics_unavailable']);

    expect(CloudflareDaily::where('site_id', $site->id)->count())->toBe(2)
        ->and(CloudflareDaily::where('site_id', $site->id)->whereNull('firewall_json')->count())->toBe(2)
        ->and(CloudflareBotDaily::where('site_id', $site->id)->count())->toBe(0);

    $bots = app(\App\Services\Integrations\CloudflareAnalytics::class)->fetchBots($site, $dayA, $dayA);
    expect($bots['available'])->toBeFalse()
        ->and($bots['reason'])->toBe('plan_does_not_expose_bot_analytics')
        ->and($bots['rows'])->toBe([]);
});

it('fails the sync with a retryable integration failure when the primary dataset errors', function () {
    [, $org] = makeOrgUser('edge-hardfail@example.com');
    $site = makeCfEdgeSite($org);

    Http::fake([
        'api.cloudflare.com/client/v4/graphql' => Http::sequence()
            ->push(cfEdgeZone(['httpRequests1dGroups' => [], 'firewallEventsAdaptiveGroups' => []]))
            ->push(cfEdgeError('zone not found'))
            ->whenEmpty(Http::response(cfEdgeError('no stubbed response'), 500)),
    ]);

    try {
        app(IntegrationManager::class)->sync($site, 'cloudflare');
        $this->fail('Expected an IntegrationFailure.');
    } catch (IntegrationFailure $e) {
        expect($e->errorCode)->toBe('cloudflare_sync_failed')
            ->and($e->retryable)->toBeTrue();
    }

    expect(CloudflareDaily::count())->toBe(0);
});

it('serves the edge overview endpoint with honest math and gap explanations', function () {
    $dayA = now()->subDay()->toDateString();
    $dayB = now()->subDays(2)->toDateString();

    [$user, $org] = makeOrgUser('edge-api@example.com');
    $site = makeCfEdgeSite($org);

    CloudflareDaily::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'date' => $dayA,
        'requests' => 1000, 'bytes' => 2000000000, 'cached_requests' => 600, 'cached_bytes' => 1200000000,
        'encrypted_requests' => 900, 'pageviews' => 300, 'threats' => 12, 'client_4xx' => 60, 'client_5xx' => 40,
        'countries_json' => ['US' => 700, 'DE' => 200], 'firewall_json' => ['block' => 7],
    ]);
    CloudflareDaily::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'date' => $dayB,
        'requests' => 500, 'bytes' => 500000000, 'cached_requests' => 100, 'cached_bytes' => 100000000,
        'encrypted_requests' => 450, 'pageviews' => 120, 'threats' => 3, 'client_4xx' => 0, 'client_5xx' => 20,
        'countries_json' => ['US' => 400], 'firewall_json' => ['block' => 2, 'challenge' => 1],
    ]);

    CloudflareBotDaily::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'date' => $dayA, 'bot_name' => 'GPTBot', 'classification' => 'ai_crawler', 'requests' => 25, 'blocked' => 0,
    ]);
    CloudflareBotDaily::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'date' => $dayB, 'bot_name' => 'GPTBot', 'classification' => 'ai_crawler', 'requests' => 15, 'blocked' => 0,
    ]);
    CloudflareBotDaily::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'date' => $dayA, 'bot_name' => 'Googlebot', 'classification' => 'search_bot', 'requests' => 120, 'blocked' => 0,
    ]);

    // GA4 side of the traffic gap.
    TrafficDaily::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'date' => $dayA, 'source' => 'ga4', 'sessions' => 30,
    ]);

    $response = $this->actingAs($user)->getJson("/api/sites/{$site->id}/cloudflare?days=30")->assertOk();

    $json = $response->json();

    expect($json['has_data'])->toBeTrue();

    $overview = $json['overview'];
    expect($overview['requests'])->toBe(1500)
        ->and($overview['bandwidth_gb'])->toBe(2.5)              // 2.5e9 / 1e9
        ->and($overview['cached_requests'])->toBe(700)
        ->and($overview['cache_hit_ratio'])->toBe(46.7)          // 700/1500
        // 52.0/90.0 serialize as ints through JSON (no zero-fraction flag).
        ->and($overview['bandwidth_saved_pct'])->toBe(52)        // 1.3e9/2.5e9
        ->and($overview['encrypted_pct'])->toBe(90)              // 1350/1500
        ->and($overview['pageviews'])->toBe(420)
        ->and($overview['threats'])->toBe(15)
        ->and($overview['client_4xx'])->toBe(60)
        ->and($overview['client_5xx'])->toBe(60)
        ->and($overview['trend'])->toHaveCount(2);

    expect($json['geo']['top_countries'][0])->toBe(['country' => 'US', 'requests' => 1100])
        ->and($json['geo']['top_countries'][1]['country'])->toBe('DE');

    expect($json['security']['threats_total'])->toBe(15)
        ->and($json['security']['actions'])->toBe(['block' => 9, 'challenge' => 1]);

    expect($json['bots']['available'])->toBeTrue()
        ->and($json['bots']['rows'][0]['bot_name'])->toBe('Googlebot') // sorted by requests desc
        ->and($json['bots']['rows'][1]['requests'])->toBe(40)          // GPTBot aggregated across days
        ->and($json['bots']['rows'][1]['classification'])->toBe('ai_crawler');

    $gap = $json['traffic_gap'];
    expect($gap['edge_requests'])->toBe(1500)
        ->and($gap['ga4_sessions'])->toBe(30)
        ->and($gap['gap_ratio_pct'])->toBe(2)  // 30/1500 — 2.0 serializes as int via JSON
        ->and($gap['explanations'])->toHaveKeys([
            'requests_are_not_sessions', 'cached_hits_have_no_pageview',
            'bots_never_hit_ga4', 'consent_and_blockers', 'misconfiguration',
        ]);

    expect($json['freshness']['last_data_date'])->toBe($dayA);

    // A cached-only section filter returns just that section.
    $filtered = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/cloudflare?days=30&sections=bots")
        ->assertOk()
        ->json();

    expect($filtered)->toHaveKeys(['range', 'has_data', 'bots'])
        ->and($filtered)->not->toHaveKey('overview');
});

it('returns has_data false with a connect hint when nothing has synced yet', function () {
    [$user, $org] = makeOrgUser('edge-empty@example.com');
    $site = makeCfEdgeSite($org, connected: false);

    $json = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/cloudflare")
        ->assertOk()
        ->json();

    expect($json['has_data'])->toBeFalse()
        ->and($json['hint'])->toBe('connect_cloudflare')
        ->and($json['message'])->toContain('Connect Cloudflare');

    // Connected but not yet synced: a calm awaiting-sync hint, not an error.
    $site->update(['cloudflare_zone_id' => 'zone123abc', 'cloudflare_api_token' => str_repeat('c', 40)]);

    $json = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/cloudflare")
        ->assertOk()
        ->json();

    expect($json['has_data'])->toBeFalse()
        ->and($json['hint'])->toBe('connected_awaiting_sync');
});

it('hides another organizations site behind a 404', function () {
    [$userA, $orgA] = makeOrgUser('edge-owner@example.com');
    $site = makeCfEdgeSite($orgA);
    CloudflareDaily::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'date' => now()->subDay()->toDateString(), 'requests' => 10,
    ]);

    [$userB] = makeOrgUser('edge-outsider@example.com');

    $this->actingAs($userB)
        ->getJson("/api/sites/{$site->id}/cloudflare")
        ->assertNotFound();
});
