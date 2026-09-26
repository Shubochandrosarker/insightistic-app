<?php

use App\Models\SeoDaily;
use App\Models\Site;
use App\Services\ContextAssembler;
use App\Support\DateRange;

it('uses Search Console data when a traffic insight has no GA4 rows', function () {
    [, $org] = makeOrgUser('context-gsc-only@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Search only',
        'domain' => 'search-only.example.com',
    ]);

    SeoDaily::create([
        'site_id' => $site->id,
        'source' => 'gsc',
        'date' => now()->subDays(2)->toDateString(),
        'clicks' => 18,
        'impressions' => 420,
    ]);

    $context = app(ContextAssembler::class)->assemble(
        'traffic',
        $site,
        DateRange::fromRequest('last_7_days', null, null, 'UTC'),
    );

    expect($context['has_data'])->toBeTrue()
        ->and($context['raw']['organic_clicks'])->toBe(18)
        ->and($context['raw']['organic_impressions'])->toBe(420)
        ->and($context['evidence'][0]['source'])->toBe('gsc');
});
