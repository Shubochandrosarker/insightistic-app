<?php

use App\Models\Alert;
use App\Models\Organization;
use App\Models\RankKeyword;
use App\Models\RankObservation;
use App\Models\Site;
use App\Services\Integrations\IntegrationFailure;
use App\Services\Rank\RankChecker;
use App\Services\Rank\RankProvider;
use Illuminate\Support\Carbon;

/**
 * Rank check orchestration (Phase 10, roadmap 13-RANK-TRACKER): observation
 * persistence, same-day repeat suppression, rank_* alert rules with the
 * doc's noise control (min movement, one alert per keyword/type/day), and
 * subset scoping — all against a fake RankProvider bound in the container.
 */

/**
 * Scriptable RankProvider fake: tests seed ->results[keywordId] (full or
 * partial result arrays) and mutate ->available / ->results between calls.
 */
class ScriptableRankProvider implements RankProvider
{
    public bool $available = true;

    /** @var array<int, array> keyed by keyword id */
    public array $results = [];

    /** @var array<int, array<int, int>> batch sizes seen per call */
    public array $calls = [];

    public function checkBatch(array $keywords): array
    {
        $this->calls[] = array_map(fn (RankKeyword $keyword) => $keyword->id, $keywords);

        $out = [];

        foreach ($keywords as $keyword) {
            $seeded = $this->results[$keyword->id] ?? [];

            $out[$keyword->id] = array_merge([
                'position'            => null,
                'ranking_url'         => null,
                'serp_features'       => null,
                'provider_request_id' => 'fake-'.$keyword->id,
                'cost_units'          => 0.0125,
            ], is_array($seeded) ? $seeded : []);
        }

        return $out;
    }

    public function available(): bool
    {
        return $this->available;
    }
}

function rankCheckerSite(Organization $org, string $domain = 'checker.example'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => $domain,
        'domain'          => $domain,
    ]);
}

function rankCheckerKeyword(Site $site, string $keyword, array $overrides = []): RankKeyword
{
    return RankKeyword::create(array_merge([
        'site_id'     => $site->id,
        'keyword'     => $keyword,
        'engine'      => 'google',
        'device'      => 'desktop',
        'active'      => true,
        'config_hash' => RankKeyword::makeConfigHash($keyword, 'google', null, 'en', 'desktop', null),
    ], $overrides));
}

function rankSeedObservation(RankKeyword $keyword, ?int $position, ?string $url = null): RankObservation
{
    return RankObservation::create([
        'keyword_id' => $keyword->id,
        'site_id'    => $keyword->site_id,
        'checked_at' => Carbon::now()->subDay(),
        'position'   => $position,
        'ranking_url' => $url,
        'cost_units' => 0,
        'created_at' => Carbon::now()->subDay(),
    ]);
}

/** Pretend the keyword was last checked days ago so it is due again.
 *  Raw query update on purpose: an Eloquent save() can silently no-op when
 *  the new timestamp lands in the same wall-clock second as the model's
 *  current attribute value (second-precision dirty comparison). */
function rankMakeDue(RankKeyword $keyword): void
{
    RankKeyword::query()->whereKey($keyword->id)
        ->update(['last_checked_at' => Carbon::now()->subDays(2)]);
}

it('persists observations and last_checked_at on the first check', function () {
    [, $org] = makeOrgUser('rank-check@example.com');
    $site = rankCheckerSite($org);

    $keyword = rankCheckerKeyword($site, 'running shoes');

    $fake = new ScriptableRankProvider;
    $fake->results[$keyword->id] = ['position' => 4, 'ranking_url' => 'https://checker.example/shoes', 'serp_features' => ['featured_snippet']];
    $checker = new RankChecker($fake);

    expect($checker->checkSite($site))->toBe(1);

    $observation = RankObservation::query()->sole();
    expect($observation->keyword_id)->toBe($keyword->id)
        ->and($observation->site_id)->toBe($site->id)
        ->and($observation->position)->toBe(4)
        ->and($observation->ranking_url)->toBe('https://checker.example/shoes')
        ->and($observation->serp_features_json)->toBe(['featured_snippet'])
        ->and($observation->provider)->toBe('dataforseo')
        ->and($observation->provider_request_id)->toBe('fake-'.$keyword->id)
        ->and((float) $observation->cost_units)->toBe(0.0125)
        ->and($observation->checked_at)->not->toBeNull();

    expect($keyword->fresh()->last_checked_at)->not->toBeNull()
        ->and(RankKeyword::query()->where('site_id', $site->id)->whereNotNull('last_checked_at')->count())->toBe(1);
});

it('skips a same-day recheck so duplicate jobs cannot duplicate observations', function () {
    [, $org] = makeOrgUser('rank-dedupe@example.com');
    $site = rankCheckerSite($org, 'dedupe.example');

    $keyword = rankCheckerKeyword($site, 'twice checked');

    $fake = new ScriptableRankProvider;
    $checker = new RankChecker($fake);

    expect($checker->checkSite($site))->toBe(1)
        ->and($checker->checkSite($site))->toBe(0)
        ->and(RankObservation::query()->count())->toBe(1)
        // The provider was called exactly once — no second bill.
        ->and(count($fake->calls))->toBe(1);

    // Inactive keywords are never picked up.
    rankCheckerKeyword($site, 'inactive one', ['active' => false]);
    expect($checker->checkSite($site))->toBe(0);
});

it('raises movement alerts with per-keyword-per-day dedupe', function () {
    [, $org] = makeOrgUser('rank-alerts@example.com');
    $site = rankCheckerSite($org, 'alerts.example');

    $entered = rankCheckerKeyword($site, 'entered shoes');
    rankSeedObservation($entered, 14, 'https://alerts.example/entered');

    $fellOut = rankCheckerKeyword($site, 'fell out shoes');
    rankSeedObservation($fellOut, 5, 'https://alerts.example/fell');

    $drift = rankCheckerKeyword($site, 'drifting shoes');
    rankSeedObservation($drift, 40, 'https://alerts.example/drift');

    $wobble = rankCheckerKeyword($site, 'wobbling shoes');
    rankSeedObservation($wobble, 40, 'https://alerts.example/wobble');

    $gone = rankCheckerKeyword($site, 'vanished shoes');
    rankSeedObservation($gone, 7, 'https://alerts.example/gone');

    $urlMoved = rankCheckerKeyword($site, 'url moved shoes');
    rankSeedObservation($urlMoved, 12, 'https://alerts.example/old-page');

    $fake = new ScriptableRankProvider;
    $fake->results = [
        $entered->id => ['position' => 5],                            // 14 → 5: entered top 10
        $fellOut->id => ['position' => 14],                           // 5 → 14: fell out of top 10
        $drift->id   => ['position' => 55],                           // 40 → 55: drift ≥ 3
        $wobble->id  => ['position' => 42],                           // 40 → 42: noise, no alert
        $gone->id    => ['position' => null],                         // 7 → not found
        $urlMoved->id => ['position' => 12, 'ranking_url' => 'https://alerts.example/new-page'], // same spot, new URL
    ];
    $checker = new RankChecker($fake);

    expect($checker->checkSite($site))->toBe(6);

    $alertFor = fn (string $type, RankKeyword $keyword) => Alert::query()
        ->where('site_id', $site->id)
        ->where('type', $type)
        ->where('message', 'like', '%keyword #'.$keyword->id.'%');

    expect($alertFor('rank_entered_top10', $entered)->count())->toBe(1)
        ->and($alertFor('rank_dropped', $fellOut)->count())->toBe(1)
        ->and($alertFor('rank_dropped', $drift)->count())->toBe(1)
        ->and($alertFor('rank_dropped', $wobble)->count())->toBe(0)
        ->and($alertFor('rank_not_found', $gone)->count())->toBe(1)
        ->and($alertFor('rank_position_changed_url', $urlMoved)->count())->toBe(1)
        ->and(Alert::query()->where('site_id', $site->id)->count())->toBe(5);

    // Severity mapping: good news is low, being gone is high.
    expect($alertFor('rank_entered_top10', $entered)->sole()->severity)->toBe('low')
        ->and($alertFor('rank_not_found', $gone)->sole()->severity)->toBe('high');

    // Noise control, part 2: a SECOND movement of the same keyword on the
    // same day stays silent — one alert per keyword/type/day.
    RankKeyword::query()->whereKey($drift->id)->get()->each(fn ($k) => rankMakeDue($k));
    $fake->results = [$drift->id => ['position' => 60]]; // 55 → 60, still a real drop

    expect($checker->checkSite($site))->toBe(1)
        ->and($alertFor('rank_dropped', $drift)->count())->toBe(1)
        ->and(RankObservation::query()->where('keyword_id', $drift->id)->count())->toBe(3);
});

it('flags a URL change only when the position holds steady', function () {
    [, $org] = makeOrgUser('rank-url@example.com');
    $site = rankCheckerSite($org, 'urlchange.example');

    $sameUrl = rankCheckerKeyword($site, 'stable url keyword');
    rankSeedObservation($sameUrl, 22, 'https://urlchange.example/page');

    $fake = new ScriptableRankProvider;
    $checker = new RankChecker($fake);

    // Identical position AND URL → nothing.
    $fake->results = [$sameUrl->id => ['position' => 22, 'ranking_url' => 'https://urlchange.example/page']];
    expect($checker->checkSite($site))->toBe(1)
        ->and(Alert::query()->count())->toBe(0);

    // Same position ±1 with a different page → exactly the URL-change alert.
    rankMakeDue($sameUrl);
    $fake->results = [$sameUrl->id => ['position' => 21, 'ranking_url' => 'https://urlchange.example/other']];
    expect($checker->checkSite($site))->toBe(1)
        ->and(Alert::query()->where('type', 'rank_position_changed_url')->count())->toBe(1)
        ->and(Alert::query()->where('type', 'rank_dropped')->count())->toBe(0)
        ->and(Alert::query()->where('type', 'rank_entered_top10')->count())->toBe(0);

    // A movement of 3+ (position, URL irrelevant) is a drop, not a URL change.
    rankMakeDue($sameUrl);
    $fake->results = [$sameUrl->id => ['position' => 30]];
    expect($checker->checkSite($site))->toBe(1)
        ->and(Alert::query()->where('type', 'rank_dropped')->count())->toBe(1)
        ->and(Alert::query()->where('type', 'rank_position_changed_url')->count())->toBe(1);
});

it('checks only the requested keyword subset', function () {
    [, $org] = makeOrgUser('rank-subset@example.com');
    $site = rankCheckerSite($org, 'subset.example');

    $first = rankCheckerKeyword($site, 'subset one');
    $second = rankCheckerKeyword($site, 'subset two');
    $third = rankCheckerKeyword($site, 'subset three');

    $fake = new ScriptableRankProvider;
    $checker = new RankChecker($fake);

    expect($checker->checkSite($site, [$first->id, $third->id]))->toBe(2)
        ->and($fake->calls[0])->toBe([$first->id, $third->id])
        ->and($second->fresh()->last_checked_at)->toBeNull()
        ->and(RankObservation::query()->count())->toBe(2);
});

it('refuses to check when the provider is not configured', function () {
    [, $org] = makeOrgUser('rank-unconfigured@example.com');
    $site = rankCheckerSite($org, 'unconfigured.example');

    rankCheckerKeyword($site, 'never checked');

    $fake = new ScriptableRankProvider;
    $fake->available = false;

    try {
        (new RankChecker($fake))->checkSite($site);
        $this->fail('Expected IntegrationFailure');
    } catch (IntegrationFailure $e) {
        expect($e->errorCode)->toBe('rank_provider_not_configured');
    }

    // Nothing persisted: no observation, no last_checked_at.
    expect(RankObservation::query()->count())->toBe(0)
        ->and(RankKeyword::query()->whereNotNull('last_checked_at')->count())->toBe(0);
});
