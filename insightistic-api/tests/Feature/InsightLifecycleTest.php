<?php

use App\Models\AiInsight;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TrafficDaily;
use App\Models\UsageCounter;
use App\Services\Ai\AiProvider;

/**
 * Insight lifecycle + proactive generation (spec Phase 6).
 *
 * The lifecycle exists because an insight list that only ever grows is a list
 * nobody reads. These tests pin the three behaviours that make it usable:
 * saving survives the live window, dismissing hides without deleting, and the
 * nightly job neither duplicates work nor spends quota the user wanted for
 * their own questions.
 */

/** A provider that always answers, so generation is deterministic. */
function fakeAiProvider(): void
{
    app()->bind(AiProvider::class, fn () => new class implements AiProvider
    {
        public function generate(string $system, array $context, ?int $identityId = null): ?array
        {
            return [
                'headline'   => 'Sessions moved',
                'summary'    => 'Traffic changed over the period.',
                'actions'    => ['Check the top landing pages', 'Compare against last month'],
                'severity'   => 'medium',
                'confidence' => 'medium',
            ];
        }

        public function model(): string
        {
            return 'test-model';
        }
    });
}

/** Site + a month of traffic, so contextual assembly reports has_data. */
function siteWithTraffic(Organization $org, string $name = 'Shop'): Site
{
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => $name,
        'domain'            => strtolower($name) . '-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
    ]);

    foreach (range(1, 5) as $i) {
        TrafficDaily::create([
            'site_id'  => $site->id,
            'date'     => now()->subDays($i)->toDateString(),
            'source'   => 'ga4',
            'sessions' => 100 + $i,
            'users'    => 80 + $i,
        ]);
    }

    return $site;
}

function makeInsight(Organization $org, Site $site, array $attrs = []): AiInsight
{
    return AiInsight::withoutGlobalScope('organization')->create(array_merge([
        'organization_id'  => $org->id,
        'site_id'          => $site->id,
        'type'             => 'traffic',
        'title'            => 'Sessions fell 12%',
        'summary'          => 'Organic sessions dropped week over week.',
        'recommendation'   => 'Review the pages that lost impressions.',
        'severity'         => 'medium',
        'priority_score'   => 5,
        'source_data_json' => ['actions' => ['Review lost impressions', 'Check indexing']],
        'ai_model'         => 'test-model',
        'status'           => 'unread',
        'expires_at'       => now()->addHours(6),
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Lifecycle endpoints
// ---------------------------------------------------------------------------

it('saves and unsaves an insight', function () {
    [$user, $org] = makeOrgUser('ins-save@example.com');
    $site = siteWithTraffic($org);
    $insight = makeInsight($org, $site);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/insights/{$insight->id}/save")
        ->assertOk()
        ->assertJsonPath('insight.saved', true);

    expect($insight->fresh()->saved_at)->not->toBeNull();

    $this->actingAs($user)
        ->deleteJson("/api/sites/{$site->id}/insights/{$insight->id}/save")
        ->assertOk()
        ->assertJsonPath('insight.saved', false);

    expect($insight->fresh()->saved_at)->toBeNull();
});

it('dismisses without deleting and can restore', function () {
    [$user, $org] = makeOrgUser('ins-dismiss@example.com');
    $site = siteWithTraffic($org);
    $insight = makeInsight($org, $site);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/insights/{$insight->id}/dismiss")
        ->assertOk()
        ->assertJsonPath('insight.dismissed', true);

    // Hidden, not gone — the row must survive so a restore is possible.
    expect(AiInsight::withoutGlobalScope('organization')->find($insight->id))->not->toBeNull();

    $live = $this->actingAs($user)->getJson("/api/sites/{$site->id}/insights?filter=live")->json();
    expect($live['insights'])->toHaveCount(0)
        ->and($live['counts']['dismissed'])->toBe(1);

    $this->actingAs($user)
        ->deleteJson("/api/sites/{$site->id}/insights/{$insight->id}/dismiss")
        ->assertOk()
        ->assertJsonPath('insight.dismissed', false);

    expect($this->actingAs($user)->getJson("/api/sites/{$site->id}/insights?filter=live")->json('insights'))
        ->toHaveCount(1);
});

it('keeps saved insights visible in the saved view after dismissal', function () {
    [$user, $org] = makeOrgUser('ins-saved-view@example.com');
    $site = siteWithTraffic($org);
    $insight = makeInsight($org, $site, ['saved_at' => now(), 'dismissed_at' => now()]);

    $saved = $this->actingAs($user)->getJson("/api/sites/{$site->id}/insights?filter=saved")->json();

    expect($saved['insights'])->toHaveCount(1)
        ->and($saved['insights'][0]['id'])->toBe($insight->id);
});

it('marks an insight stale once its freshness window has passed', function () {
    [$user, $org] = makeOrgUser('ins-stale@example.com');
    $site = siteWithTraffic($org);
    makeInsight($org, $site, ['expires_at' => now()->subHour()]);
    makeInsight($org, $site, ['expires_at' => now()->addHour()]);

    $rows = collect($this->actingAs($user)->getJson("/api/sites/{$site->id}/insights")->json('insights'));

    expect($rows->where('stale', true))->toHaveCount(1)
        ->and($rows->where('stale', false))->toHaveCount(1);
});

it('exports an insight as task-ready markdown and records the action', function () {
    [$user, $org] = makeOrgUser('ins-action@example.com');
    $site = siteWithTraffic($org);
    $insight = makeInsight($org, $site);

    $task = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/insights/{$insight->id}/action")
        ->assertOk()
        ->json('task');

    expect($task['markdown'])->toContain('Sessions fell 12%')
        ->and($task['markdown'])->toContain('- [ ] Review lost impressions')
        ->and($task['actions'])->toHaveCount(2);

    $insight->refresh();
    expect($insight->actioned_at)->not->toBeNull()
        ->and($insight->status)->toBe('done');
});

it('falls back to the recommendation when an insight carries no action list', function () {
    [$user, $org] = makeOrgUser('ins-legacy-action@example.com');
    $site = siteWithTraffic($org);
    // Legacy digests predate the actions array; they must still export.
    $insight = makeInsight($org, $site, ['type' => 'weekly', 'source_data_json' => ['sessions' => 10]]);

    $task = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/insights/{$insight->id}/action")
        ->assertOk()
        ->json('task');

    expect($task['actions'])->toBe(['Review the pages that lost impressions.']);
});

it('refuses lifecycle changes to another organization\'s insight', function () {
    [, $orgA] = makeOrgUser('ins-tenant-a@example.com');
    [$userB] = makeOrgUser('ins-tenant-b@example.com');

    $siteA = siteWithTraffic($orgA, 'AShop');
    $insightA = makeInsight($orgA, $siteA);

    // 404, not 403: cross-tenant ids must not be confirmable as existing.
    $this->actingAs($userB)
        ->postJson("/api/sites/{$siteA->id}/insights/{$insightA->id}/dismiss")
        ->assertNotFound();

    expect($insightA->fresh()->dismissed_at)->toBeNull();
});

it('returns 404 for an unknown insight id', function () {
    [$user, $org] = makeOrgUser('ins-missing@example.com');
    $site = siteWithTraffic($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/insights/999999/save")
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Proactive generation
// ---------------------------------------------------------------------------

it('generates proactive insights for connected sites', function () {
    fakeAiProvider();
    [, $org] = makeOrgUser('ins-proactive@example.com');
    $site = siteWithTraffic($org);

    $this->artisan('insightistic:insights-generate', ['--site' => $site->id, '--type' => ['traffic']])
        ->assertSuccessful();

    $rows = AiInsight::withoutGlobalScope('organization')->where('site_id', $site->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->type)->toBe('traffic')
        // organization_id must be stamped even though nobody is authenticated.
        ->and($rows->first()->organization_id)->toBe($org->id)
        ->and($rows->first()->expires_at)->not->toBeNull();
});

it('does not regenerate a type that already has a fresh insight', function () {
    fakeAiProvider();
    [, $org] = makeOrgUser('ins-dedupe@example.com');
    $site = siteWithTraffic($org);
    makeInsight($org, $site, ['type' => 'traffic', 'expires_at' => now()->addHours(6)]);

    $this->artisan('insightistic:insights-generate', ['--site' => $site->id, '--type' => ['traffic']])
        ->assertSuccessful();

    expect(AiInsight::withoutGlobalScope('organization')->where('site_id', $site->id)->count())->toBe(1);
});

it('re-raises a dismissed finding on the next cycle', function () {
    fakeAiProvider();
    [, $org] = makeOrgUser('ins-dismissed-regen@example.com');
    $site = siteWithTraffic($org);
    // "Not now" is not "never" — a dismissal must not suppress the next run.
    makeInsight($org, $site, ['type' => 'traffic', 'dismissed_at' => now()]);

    $this->artisan('insightistic:insights-generate', ['--site' => $site->id, '--type' => ['traffic']])
        ->assertSuccessful();

    expect(AiInsight::withoutGlobalScope('organization')->where('site_id', $site->id)->count())->toBe(2);
});

it('stops at the plan limit instead of borrowing against it', function () {
    fakeAiProvider();
    [, $org] = makeOrgUser('ins-limit@example.com');
    $site = siteWithTraffic($org);

    // Burn the whole monthly allowance before the run.
    $counter = UsageCounter::currentFor($org->fresh());
    $counter->update(['ai_insights_used' => $org->plan->ai_insight_limit]);

    $this->artisan('insightistic:insights-generate', ['--site' => $site->id, '--type' => ['traffic']])
        ->assertSuccessful();

    expect(AiInsight::withoutGlobalScope('organization')->where('site_id', $site->id)->count())->toBe(0);
});

it('spends no quota on a site with no evidence', function () {
    fakeAiProvider();
    [, $org] = makeOrgUser('ins-nodata@example.com');
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => 'Empty',
        'domain'            => 'empty-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
    ]);

    $this->artisan('insightistic:insights-generate', ['--site' => $site->id, '--type' => ['traffic']])
        ->assertSuccessful();

    expect(AiInsight::withoutGlobalScope('organization')->where('site_id', $site->id)->count())->toBe(0)
        ->and(UsageCounter::currentFor($org->fresh())->ai_insights_used)->toBe(0);
});

it('skips organizations scheduled for deletion', function () {
    fakeAiProvider();
    [, $org] = makeOrgUser('ins-deleting@example.com');
    $site = siteWithTraffic($org);

    $org->forceFill([
        'deletion_requested_at'  => now(),
        'deletion_scheduled_for' => now()->addDays(30),
    ])->save();

    $this->artisan('insightistic:insights-generate', ['--site' => $site->id])
        ->assertSuccessful();

    expect(AiInsight::withoutGlobalScope('organization')->where('site_id', $site->id)->count())->toBe(0);
});

it('reports without writing in dry-run mode', function () {
    fakeAiProvider();
    [, $org] = makeOrgUser('ins-dry@example.com');
    $site = siteWithTraffic($org);

    $this->artisan('insightistic:insights-generate', ['--site' => $site->id, '--type' => ['traffic'], '--dry-run' => true])
        ->assertSuccessful();

    expect(AiInsight::withoutGlobalScope('organization')->where('site_id', $site->id)->count())->toBe(0)
        ->and(UsageCounter::currentFor($org->fresh())->ai_insights_used)->toBe(0);
});

it('rejects an unknown insight type rather than silently skipping it', function () {
    $this->artisan('insightistic:insights-generate', ['--type' => ['nonsense']])
        ->assertFailed();
});

/*
 * Nov 2026 regression: the ai-pause hotfix referenced $availability inside
 * the chunkById closure without capturing it, so every scheduled run died
 * with "Undefined variable $availability" before writing a row.
 */
it('runs across paused and unpaused sites without crashing (ai-pause closure regression)', function () {
    fakeAiProvider();
    [, $org] = makeOrgUser('ins-pause-ok@example.com');
    $live = siteWithTraffic($org, 'Live shop');

    [, $pausedOrg] = makeOrgUser('ins-pause-org@example.com');
    $pausedOrg->forceFill(['ai_settings_json' => ['paused' => true]])->save();
    $paused = siteWithTraffic($pausedOrg, 'Paused shop');

    $this->artisan('insightistic:insights-generate', ['--type' => ['traffic']])
        ->assertSuccessful();

    expect(AiInsight::withoutGlobalScope('organization')->where('site_id', $live->id)->count())->toBe(1)
        ->and(AiInsight::withoutGlobalScope('organization')->where('site_id', $paused->id)->count())->toBe(0);
});

it('skips a site whose domain is in INSIGHTISTIC_AI_PAUSED_DOMAINS and logs the reason', function () {
    fakeAiProvider();
    config(['insightistic.ai.paused_domains' => ['paused-domain.example']]);
    [, $org] = makeOrgUser('ins-pause-domain@example.com');
    $site = siteWithTraffic($org);
    $site->forceFill(['domain' => 'www.paused-domain.example'])->save();

    \Illuminate\Support\Facades\Log::spy();

    $this->artisan('insightistic:insights-generate', ['--site' => $site->id, '--type' => ['traffic']])
        ->assertSuccessful();

    expect(AiInsight::withoutGlobalScope('organization')->where('site_id', $site->id)->count())->toBe(0);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
        ->withArgs(fn ($msg, $ctx = []) => $msg === 'ai_job.insights-generate.skip'
            && ($ctx['site_id'] ?? null) === $site->id && ($ctx['reason'] ?? null) === 'ai_paused')
        ->once();
});

it('records a run summary with per-reason skip counts', function () {
    fakeAiProvider();
    [, $org] = makeOrgUser('ins-summary@example.com');
    $site = siteWithTraffic($org);
    makeInsight($org, $site, ['type' => 'traffic', 'expires_at' => now()->addHours(6)]);

    $this->artisan('insightistic:insights-generate', ['--site' => $site->id, '--type' => ['traffic']])
        ->assertSuccessful();

    $summary = \Illuminate\Support\Facades\Cache::get(\App\Services\Observability\AiJobRunLog::cacheKey('insights-generate'));
    expect($summary['eligible'])->toBe(1)
        ->and($summary['written'])->toBe(0)
        ->and($summary['reasons'])->toBe(['fresh_insight_exists' => 1]);
});
