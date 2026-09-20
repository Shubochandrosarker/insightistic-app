<?php

use App\Models\RankKeyword;
use App\Models\SeoQueryDaily;
use App\Models\Site;
use App\Services\Rank\RankSuggestions;
use App\Support\SeoGrain;
use Illuminate\Support\Str;

/**
 * Rank keyword auto-suggestions (Phase 10, doc 13): source filtering out
 * of seo_query_daily (28-day window, impressions ordering), the
 * case-insensitive already-tracked exclusion, the limit, and confirm()'s
 * duplicate + quota behaviour.
 *
 * The rank_* models/table belong to the rank engine workstream; these
 * tests create rows directly through the pinned model contracts.
 */

function rankSiteForSuggestions(string $email = 'rank-suggest@example.com'): array
{
    [$user, $org] = makeOrgUser($email, ['tracked_keywords' => 10]);

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => 'suggest.example.com',
        'domain'            => 'suggest.example.com',
        'connection_status' => 'connected',
    ]);

    return [$user, $org, $site];
}

function makeQueryRow(Site $site, string $query, string $date, int $impressions, int $clicks = 0): SeoQueryDaily
{
    return SeoQueryDaily::create([
        'site_id'     => $site->id,
        'date'        => $date,
        'query_hash'  => SeoGrain::hash($query),
        'query'       => $query,
        'clicks'      => $clicks,
        'impressions' => $impressions,
        'created_at'  => $date . ' 12:00:00',
    ]);
}

function makeSuggestKeyword(Site $site, string $keyword, array $overrides = []): RankKeyword
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

it('suggests the highest-impression untracked queries inside the 28-day window', function () {
    [, , $site] = rankSiteForSuggestions();

    // Inside the window, impressions decide the order; the same query on
    // two days sums up.
    makeQueryRow($site, 'blue widgets', now()->subDays(3)->toDateString(), 300, 10);
    makeQueryRow($site, 'blue widgets', now()->subDays(1)->toDateString(), 200, 5);
    makeQueryRow($site, 'red widgets', now()->subDay()->toDateString(), 400, 2);

    // Outside the 28-day window: high impressions, must be excluded.
    makeQueryRow($site, 'ancient query', now()->subDays(40)->toDateString(), 9000);

    // Zero-impression noise outside the window ordering top — dated today
    // but still below every counted query.
    makeQueryRow($site, 'tiny query', now()->toDateString(), 1);

    $suggestions = app(RankSuggestions::class)->suggest($site);

    expect(array_column($suggestions, 'query'))->toBe(['blue widgets', 'red widgets', 'tiny query'])
        ->and($suggestions[0]['impressions'])->toBe(500)
        ->and($suggestions[0]['clicks'])->toBe(15)
        ->and($suggestions[1]['impressions'])->toBe(400)
        ->and(collect($suggestions)->every(fn ($s) => $s['tracked'] === false))->toBeTrue();
});

it('excludes already-tracked queries case-insensitively and respects the limit', function () {
    [, , $site] = rankSiteForSuggestions('rank-suggest-tracked@example.com');

    makeSuggestKeyword($site, 'Blue Widgets');

    makeQueryRow($site, 'blue widgets', now()->subDay()->toDateString(), 900, 30);
    makeQueryRow($site, 'RED WIDGETS', now()->subDay()->toDateString(), 500, 8);
    makeQueryRow($site, 'green widgets', now()->subDays(2)->toDateString(), 100, 1);

    $service = app(RankSuggestions::class);

    $suggestions = $service->suggest($site, 20);

    expect(array_column($suggestions, 'query'))->toBe(['RED WIDGETS', 'green widgets'])
        ->and($suggestions[0]['tracked'])->toBeFalse();

    // limit truncates the ranked list, not the source window.
    $limited = $service->suggest($site, 1);

    expect(array_column($limited, 'query'))->toBe(['RED WIDGETS']);
});

it('confirm creates active keywords with tracker defaults and skips duplicates', function () {
    [, , $site] = rankSiteForSuggestions('rank-suggest-confirm@example.com');

    makeSuggestKeyword($site, 'existing keyword');

    $created = app(RankSuggestions::class)->confirm($site, [
        '  New Keyword  ',     // padded
        'new keyword',         // same keyword, different case -> skipped
        'Existing KEYWORD',    // already tracked (case-insensitive) -> skipped
        'Another One',
        'Another One',         // duplicate inside the batch -> skipped
    ]);

    expect($created)->toBe(2);

    $rows = RankKeyword::where('site_id', $site->id)->where('active', true)
        ->orderBy('id')->get();

    expect($rows)->toHaveCount(3);

    $fresh = $rows->where('keyword', 'New Keyword')->first();

    expect($fresh->engine)->toBe('google')
        ->and($fresh->device)->toBe('desktop')
        ->and($fresh->country)->toBeNull()
        ->and($fresh->language)->toBeNull()
        ->and($fresh->active)->toBeTrue()
        ->and($fresh->config_hash)->toBe(RankKeyword::makeConfigHash('New Keyword', 'google', null, null, 'desktop', null));

    // Re-confirming is a no-op, not a duplicate row.
    expect(app(RankSuggestions::class)->confirm($site, ['New Keyword']))->toBe(0);
});

it('confirm stops at the tracked_keywords quota instead of failing', function () {
    [$user, $org] = makeOrgUser('rank-suggest-quota@example.com', ['tracked_keywords' => 3]);

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => 'quota.example.com',
        'domain'            => 'quota.example.com',
        'connection_status' => 'connected',
    ]);

    makeSuggestKeyword($site, 'already tracked one');

    // Quota 3, used 1 -> room for exactly 2 of the 4 requested.
    $created = app(RankSuggestions::class)->confirm($site, [
        'first', 'second', 'third', 'fourth',
    ]);

    expect($created)->toBe(2)
        ->and(RankKeyword::where('site_id', $site->id)->where('active', true)->count())->toBe(3)
        ->and(RankKeyword::where('site_id', $site->id)->where('keyword', 'fourth')->exists())->toBeFalse();
});
