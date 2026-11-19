<?php

use App\Models\CrawlRun;
use App\Models\RankKeyword;

it('reports org-wide tracked keyword, crawl page and AI prompt run meters on the subscription payload', function () {
    [$user, $org] = makeOrgUser('usage-meters@example.com', null, 'owner');
    $site = makeSiteFor($org);
    $token = $user->createToken('t')->plainTextToken;

    foreach (['alpha', 'beta'] as $kw) {
        RankKeyword::create([
            'site_id' => $site->id, 'keyword' => $kw, 'engine' => 'google', 'device' => 'desktop', 'active' => true,
            'config_hash' => RankKeyword::makeConfigHash($kw, 'google', null, 'en', 'desktop', null),
        ]);
    }
    RankKeyword::create([
        'site_id' => $site->id, 'keyword' => 'paused', 'engine' => 'google', 'device' => 'desktop', 'active' => false,
        'config_hash' => RankKeyword::makeConfigHash('paused', 'google', null, 'en', 'desktop', null),
    ]);
    CrawlRun::create(['site_id' => $site->id, 'status' => 'completed', 'trigger' => 'manual', 'max_pages' => 5000, 'pages_crawled' => 300]);
    $old = CrawlRun::create(['site_id' => $site->id, 'status' => 'completed', 'trigger' => 'manual', 'max_pages' => 900, 'pages_crawled' => 900]);
    $old->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()])->save();

    $res = $this->withToken($token)->getJson('/api/billing/subscription')->assertOk();

    expect($res->json('usage.tracked_keywords.used'))->toBe(2)
        ->and($res->json('usage.crawl_pages.used'))->toBe(300)
        ->and($res->json('usage.ai_prompt_runs.used'))->toBe(0)
        ->and($res->json('usage.tracked_keywords'))->toHaveKey('limit')
        ->and($res->json('usage.crawl_pages'))->toHaveKey('limit')
        ->and($res->json('usage.ai_prompt_runs'))->toHaveKey('limit');
});
