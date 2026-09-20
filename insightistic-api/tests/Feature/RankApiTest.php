<?php

use App\Jobs\RankCheckJob;
use App\Models\RankKeyword;
use App\Models\RankObservation;
use App\Models\SeoQueryDaily;
use App\Models\Site;
use App\Models\User;
use App\Support\SeoGrain;
use Illuminate\Support\Facades\Queue;

/**
 * Rank tracker API (Phase 10, doc 13): tenancy, role gates, the
 * tracked_keywords quota envelope, duplicate skipping, the check guards
 * (provider configured / one check per keyword per day), suggestions +
 * confirmation, the KPI payload and the CSV export.
 *
 * The rank_* models, RankCheckJob and the provider belong to the rank
 * engine workstream; these tests create rows directly through the pinned
 * model contracts and fake the queue — nothing here talks to DataForSEO.
 */

function makeRankSite(string $email, array $limits = ['tracked_keywords' => 100]): array
{
    [$user, $org] = makeOrgUser($email, $limits);

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => $email,
        'domain'            => $email,
        'connection_status' => 'connected',
    ]);

    return [$user, $org, $site];
}

function makeTrackedKeyword(Site $site, string $keyword, array $overrides = []): RankKeyword
{
    $attributes = array_replace([
        'site_id'  => $site->id,
        'keyword'  => $keyword,
        'engine'   => 'google',
        'device'   => 'desktop',
        'active'   => true,
    ], $overrides);

    $row = new RankKeyword($attributes);
    $row->config_hash = RankKeyword::makeConfigHash(
        $attributes['keyword'],
        $attributes['engine'],
        $attributes['country'] ?? null,
        $attributes['language'] ?? null,
        $attributes['device'],
        $attributes['location'] ?? null,
    );
    $row->save();

    return $row;
}

function makeRankObservation(Site $site, RankKeyword $keyword, ?int $position, string $checkedAt, int $costUnits = 1): RankObservation
{
    return RankObservation::create([
        'keyword_id'          => $keyword->id,
        'site_id'             => $site->id,
        'checked_at'          => $checkedAt,
        'position'            => $position,
        'ranking_url'         => $position === null ? null : "https://{$site->domain}/target",
        'serp_features_json'  => null,
        'provider'            => 'dataforseo',
        'provider_request_id' => 'req-' . uniqid(),
        'cost_units'          => $costUnits,
        'created_at'          => $checkedAt,
    ]);
}

function makeGscQuery(Site $site, string $query, int $impressions, int $clicks = 0, int $daysAgo = 1): SeoQueryDaily
{
    return SeoQueryDaily::create([
        'site_id'     => $site->id,
        'date'        => now()->subDays($daysAgo)->toDateString(),
        'query_hash'  => SeoGrain::hash($query),
        'query'       => $query,
        'clicks'      => $clicks,
        'impressions' => $impressions,
        'created_at'  => now()->subDays($daysAgo),
    ]);
}

/** Read a job's public constructor properties, tolerating either naming. */
function rankJobProps(object $job): array
{
    return get_object_vars($job);
}

it('hides every rank surface from another organization', function () {
    [$userA] = makeRankSite('rank-a@example.com');
    [, , $siteB] = makeRankSite('rank-b@example.com');

    makeTrackedKeyword($siteB, 'secret keyword');

    $this->actingAs($userA)
        ->getJson("/api/sites/{$siteB->id}/rank")
        ->assertNotFound();

    $this->actingAs($userA)
        ->getJson("/api/sites/{$siteB->id}/rank/suggestions")
        ->assertNotFound();

    $this->actingAs($userA)
        ->postJson("/api/sites/{$siteB->id}/rank/keywords", ['keywords' => [['keyword' => 'x']]])
        ->assertNotFound();
});

it('opens reads to analysts but blocks every write', function () {
    Queue::fake();

    [$owner, $org, $site] = makeRankSite('rank-owner@example.com');

    $analyst = User::create([
        'name' => 'Analyst', 'email' => 'rank-analyst@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($analyst->id, ['role' => 'analyst']);

    $keyword = makeTrackedKeyword($site, 'watched keyword');

    // Reads are open to anyone with site access.
    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/rank")->assertOk();
    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/rank/suggestions")->assertOk();
    $this->actingAs($analyst)
        ->getJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}/history")
        ->assertOk();

    // Writes are owner/admin only.
    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/rank/keywords", ['keywords' => [['keyword' => 'new kw']]])
        ->assertForbidden();
    $this->actingAs($analyst)
        ->deleteJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}")
        ->assertForbidden();
    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}/check")
        ->assertForbidden();
    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/rank/check")
        ->assertForbidden();
    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/rank/suggestions/confirm", ['queries' => ['q']])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('creates keywords with tracker defaults, skipping duplicates silently', function () {
    [$user, , $site] = makeRankSite('rank-create@example.com');

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/keywords", ['keywords' => [
            ['keyword' => 'seo tools'],
            ['keyword' => 'rank checker', 'country' => 'US', 'device' => 'mobile'],
            ['keyword' => 'seo tools'], // duplicate inside the batch
        ]])
        ->assertOk()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('skipped', 1);

    $rows = RankKeyword::where('site_id', $site->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->engine)->toBe('google')
        ->and($rows[0]->device)->toBe('desktop')
        ->and($rows[0]->country)->toBeNull()
        ->and($rows[0]->active)->toBeTrue()
        ->and($rows[0]->config_hash)->toBe(RankKeyword::makeConfigHash('seo tools', 'google', null, null, 'desktop', null))
        ->and($rows[1]->country)->toBe('US')
        ->and($rows[1]->device)->toBe('mobile');

    // Re-submitting an existing config counts as skipped, not created.
    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/keywords", ['keywords' => [
            ['keyword' => 'rank checker', 'country' => 'US', 'device' => 'mobile'], // exact config re-submit
            ['keyword' => 'brand new one'],
        ]])
        ->assertOk()
        ->assertJsonPath('created', 1)
        ->assertJsonPath('skipped', 1);

    expect(RankKeyword::where('site_id', $site->id)->count())->toBe(3);
});

it('rejects keyword creation past the tracked_keywords quota with the house envelope', function () {
    [$user, , $site] = makeRankSite('rank-quota@example.com', ['tracked_keywords' => 1]);

    makeTrackedKeyword($site, 'the only slot');

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/keywords", ['keywords' => [['keyword' => 'one too many']]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'tracked_keyword_limit_reached')
        ->assertJsonPath('limit.key', 'tracked_keywords')
        ->assertJsonPath('limit.max', 1)
        ->assertJsonPath('limit.used', 1);

    expect(RankKeyword::where('site_id', $site->id)->count())->toBe(1);

    // Re-submitting the EXISTING keyword consumes no budget -> no rejection.
    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/keywords", ['keywords' => [['keyword' => 'the only slot']]])
        ->assertOk()
        ->assertJsonPath('created', 0)
        ->assertJsonPath('skipped', 1);
});

it('soft-deletes a keyword, keeping its observations for history', function () {
    [$user, , $site] = makeRankSite('rank-delete@example.com');

    $keyword = makeTrackedKeyword($site, 'doomed keyword');
    makeRankObservation($site, $keyword, 8, now()->subDays(2)->toDateTimeString());

    $this->actingAs($user)
        ->deleteJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}")
        ->assertOk()
        ->assertJsonPath('ok', true);

    $fresh = $keyword->fresh();

    expect($fresh->active)->toBeFalse()
        ->and(RankKeyword::where('site_id', $site->id)->count())->toBe(1)
        ->and(RankObservation::where('keyword_id', $keyword->id)->count())->toBe(1);

    // Deleted keywords leave the overview table.
    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/rank")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    // Re-posting the same keyword re-tracks it (reactivates) instead of
    // silently skipping — the only way back after a soft delete.
    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/keywords", [
            'keywords' => [['keyword' => 'doomed keyword']],
        ])
        ->assertOk();

    expect($keyword->fresh()->active)->toBeTrue()
        // Reactivation does not duplicate the observation history.
        ->and(RankObservation::where('keyword_id', $keyword->id)->count())->toBe(1);
});

it('guards a single keyword check on provider config and the same-day rule', function () {
    Queue::fake();

    [$user, , $site] = makeRankSite('rank-check@example.com');
    $keyword = makeTrackedKeyword($site, 'checked keyword');

    // No DataForSEO credentials in the test config -> fail fast, no job.
    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}/check")
        ->assertStatus(422)
        ->assertJsonPath('code', 'rank_provider_not_configured');

    Queue::assertNothingPushed();

    config(['services.dataforseo.login' => 'login', 'services.dataforseo.password' => 'secret']);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}/check")
        ->assertOk()
        ->assertJsonPath('dispatched', true);

    Queue::assertPushed(RankCheckJob::class, function (RankCheckJob $job) use ($site, $keyword) {
        $props = rankJobProps($job);
        $siteId = $props['siteId'] ?? $props['site_id'] ?? null;
        $keywordIds = $props['keywordIds'] ?? $props['keyword_ids'] ?? null;

        return $siteId === $site->id && $keywordIds === [$keyword->id];
    });

    // Same calendar day -> the recheck would only re-observe a SERP the
    // tracker already saw today.
    $keyword->update(['last_checked_at' => now()]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}/check")
        ->assertStatus(422)
        ->assertJsonPath('code', 'already_checked_today');

    // Yesterday's check is fair game again.
    $keyword->update(['last_checked_at' => now()->subDay()]);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}/check")
        ->assertOk();

    Queue::assertPushed(RankCheckJob::class, 2);
});

it('queues a whole-site check for every active keyword', function () {
    Queue::fake();

    [$user, , $site] = makeRankSite('rank-check-site@example.com');
    makeTrackedKeyword($site, 'active one');
    makeTrackedKeyword($site, 'active two');
    makeTrackedKeyword($site, 'retired one', ['active' => false]);

    config(['services.dataforseo.login' => 'login', 'services.dataforseo.password' => 'secret']);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/check")
        ->assertOk()
        ->assertJsonPath('dispatched', true)
        ->assertJsonPath('keywords', 2);

    Queue::assertPushed(RankCheckJob::class, function (RankCheckJob $job) use ($site) {
        $props = rankJobProps($job);
        $siteId = $props['siteId'] ?? $props['site_id'] ?? null;
        $keywordIds = $props['keywordIds'] ?? $props['keyword_ids'] ?? null;

        return $siteId === $site->id && $keywordIds === null;
    });
});

it('builds the overview KPIs site-wide and labels GSC impressions apart from SERP positions', function () {
    [$user, , $site] = makeRankSite('rank-kpis@example.com');

    $alpha = makeTrackedKeyword($site, 'Alpha Widget');
    $beta = makeTrackedKeyword($site, 'beta widget');

    // alpha: 5 -> 3 (improved). beta: 12 -> 15 (exactly a 3-place drop ->
    // declined, past the noise floor).
    makeRankObservation($site, $alpha, 5, now()->subDay()->toDateTimeString(), 1);
    makeRankObservation($site, $alpha, 3, now()->subHours(2)->toDateTimeString(), 2);
    makeRankObservation($site, $beta, 12, now()->subDay()->toDateTimeString(), 1);
    makeRankObservation($site, $beta, 15, now()->subHours(2)->toDateTimeString(), 1);

    // GSC side: the matching query's impressions ride along as a SEPARATE
    // field (impressions_28d), never merged into the tracker positions.
    makeGscQuery($site, 'alpha widget', 777, 40);

    $response = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/rank")
        ->assertOk();

    $response->assertJsonPath('provider_configured', false)
        ->assertJsonPath('kpis.tracked', 2)
        ->assertJsonPath('kpis.top3', 1)
        ->assertJsonPath('kpis.top10', 1)
        ->assertJsonPath('kpis.top20', 2)
        ->assertJsonPath('kpis.not_found', 0)
        ->assertJsonPath('kpis.improved', 1)
        ->assertJsonPath('kpis.declined', 1)
        // (1.0 + 0.25) / 2 * 100 = 62.5
        ->assertJsonPath('kpis.visibility', 62.5)
        ->assertJsonPath('meta.total', 2);

    $keywords = collect($response->json('keywords'))->keyBy('keyword');

    $alphaRow = $keywords['Alpha Widget'];
    expect($alphaRow['latest']['position'])->toBe(3)
        ->and($alphaRow['latest']['checked_at'])->not->toBeNull()
        ->and($alphaRow['previous_position'])->toBe(5)
        ->and($alphaRow['best_position'])->toBe(3)
        ->and($alphaRow['impressions_28d'])->toBe(777)
        ->and($alphaRow['cost_30d'])->toEqual(3.0);

    $betaRow = $keywords['beta widget'];
    expect($betaRow['latest']['position'])->toBe(15)
        ->and($betaRow['previous_position'])->toBe(12)
        ->and($betaRow['best_position'])->toBe(12)
        ->and($betaRow['impressions_28d'])->toBeNull()
        ->and($betaRow['cost_30d'])->toEqual(2.0);
});

it('serves keyword history newest checks included and scoped to the site', function () {
    [$user, , $site] = makeRankSite('rank-history@example.com');

    $keyword = makeTrackedKeyword($site, 'historic keyword');

    foreach ([14, 9, 4, null] as $i => $position) {
        makeRankObservation($site, $keyword, $position, now()->subDays(4 - $i)->toDateTimeString(), $i + 1);
    }

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}/history")
        ->assertOk()
        ->assertJsonPath('keyword.id', $keyword->id)
        ->assertJsonPath('keyword.keyword', 'historic keyword');

    $history = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}/history")
        ->json('history');

    expect($history)->toHaveCount(4)
        // Newest first: the latest check (1 day ago) found nothing.
        ->and($history[0]['position'])->toBeNull()
        ->and($history[0]['ranking_url'])->toBeNull()
        ->and($history[0]['cost_units'])->toEqual(4.0)
        ->and($history[1]['position'])->toBe(4)
        ->and($history[1]['ranking_url'])->toContain($site->domain)
        ->and($history[3]['position'])->toBe(14)
        ->and($history[3]['cost_units'])->toEqual(1.0);

    // Another org cannot read the history behind a foreign keyword id.
    [$otherUser] = makeRankSite('rank-history-other@example.com');
    $this->actingAs($otherUser)
        ->getJson("/api/sites/{$site->id}/rank/keywords/{$keyword->id}/history")
        ->assertNotFound();
});

it('lists untracked GSC queries as suggestions and confirms them into keywords', function () {
    Queue::fake();

    [$user, , $site] = makeRankSite('rank-suggest-api@example.com');

    makeTrackedKeyword($site, 'Blue Widgets');
    makeGscQuery($site, 'blue widgets', 900, 30);
    makeGscQuery($site, 'rank tracker tool', 500, 12);
    makeGscQuery($site, 'serp api', 100, 2);

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/rank/suggestions")
        ->assertOk();

    $suggestions = collect($this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/rank/suggestions")
        ->json('suggestions'));

    expect($suggestions->count())->toBe(2)
        ->and($suggestions->pluck('query')->all())->toBe(['rank tracker tool', 'serp api'])
        ->and($suggestions->first()['impressions'])->toBe(500)
        ->and($suggestions->first()['tracked'])->toBeFalse();

    // Confirmation consumes the tracked quota and requires owner/admin
    // (covered by the role-gate test above).
    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/rank/suggestions/confirm", [
            'queries' => ['rank tracker tool', 'serp api'],
        ])
        ->assertOk()
        ->assertJsonPath('created', 2);

    expect(RankKeyword::where('site_id', $site->id)->where('active', true)->count())->toBe(3);

    // Suggestions shrink to nothing once everything is tracked.
    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/rank/suggestions")
        ->assertOk()
        ->assertJsonCount(0, 'suggestions');
});

it('exports the latest observation per keyword as rank.csv', function () {
    [$user, , $site] = makeRankSite('rank-csv@example.com');

    $tracked = makeTrackedKeyword($site, 'csv keyword', ['country' => 'DE']);
    makeRankObservation($site, $tracked, 4, now()->subHour()->toDateTimeString(), 2);

    $neverChecked = makeTrackedKeyword($site, 'not checked yet');

    $response = $this->actingAs($user)
        ->get("/api/sites/{$site->id}/rank.csv")
        ->assertOk();

    $content = $response->streamedContent();
    $lines = array_map('trim', explode("\n", trim($content)));

    expect($lines)->toHaveCount(3)
        // fputcsv quotes header cells and data cells containing spaces.
        ->and($lines[0])->toBe('Keyword,Engine,Country,Device,Position,URL,"Last checked","Cost 30d"')
        ->and($lines[1])->toContain('"csv keyword",google,DE,desktop,4,')
        ->and($lines[1])->toContain('https://' . $site->domain)
        ->and($lines[1])->toContain(',2')
        // Not-checked keywords still export, with empty position/URL/date
        // and a zero cost.
        ->and($lines[2])->toBe('"not checked yet",google,,desktop,,,,0');
});
