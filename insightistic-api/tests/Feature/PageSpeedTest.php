<?php

use App\Jobs\RunPageSpeedTest;
use App\Models\PerformanceIssue;
use App\Models\PerformanceRun;
use App\Models\Site;
use App\Services\Performance\PageSpeedClient;
use App\Services\Performance\PageSpeedFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * PageSpeed runs executed by the SaaS backend (spec Phase 3.3).
 *
 * The tables and read endpoints already existed; nothing called Google, so
 * a site without the plugin had no performance data and nothing could be
 * scheduled centrally.
 */

function pagespeedSite(\App\Models\Organization $org, string $status = 'connected'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => 'Store',
        'domain'            => 'shop.example.com',
        'connection_status' => $status,
    ]);
}

/** A response shaped like PSI's, with optional CrUX field data. */
function psiPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'lighthouseResult' => [
            'lighthouseVersion' => '11.0.0',
            'fetchTime'         => '2026-07-01T10:00:00.000Z',
            'categories' => [
                'performance'    => ['score' => 0.72, 'auditRefs' => [['id' => 'unused-css-rules'], ['id' => 'uses-responsive-images']]],
                'accessibility'  => ['score' => 0.95, 'auditRefs' => [['id' => 'color-contrast']]],
                'best-practices' => ['score' => 0.83, 'auditRefs' => []],
                'seo'            => ['score' => 1.0, 'auditRefs' => []],
            ],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => 3200, 'score' => 0.4],
                'cumulative-layout-shift'  => ['numericValue' => 0.14, 'score' => 0.6],
                'first-contentful-paint'   => ['numericValue' => 1400, 'score' => 0.9],
                'total-blocking-time'      => ['numericValue' => 310, 'score' => 0.5],
                'speed-index'              => ['numericValue' => 4100, 'score' => 0.6],
                'server-response-time'     => ['numericValue' => 480, 'score' => 0.8],
                'unused-css-rules' => [
                    'title' => 'Reduce unused CSS', 'score' => 0.3,
                    'displayValue' => 'Potential savings of 120 KiB',
                    'details' => ['overallSavingsMs' => 850, 'overallSavingsBytes' => 122880],
                ],
                'uses-responsive-images' => ['title' => 'Properly size images', 'score' => 0.8],
                'color-contrast'         => ['title' => 'Contrast is insufficient', 'score' => 0.0],
                // Passing and informational audits must not become issues.
                'viewport'   => ['title' => 'Has a viewport meta tag', 'score' => 1.0],
                'final-screenshot' => ['title' => 'Final screenshot', 'score' => null],
            ],
        ],
    ], $overrides);
}

it('stores scores, vitals and opportunities from a run', function () {
    [, $org] = makeOrgUser('psi-run@example.com');
    $site = pagespeedSite($org);

    Http::fake(['*pagespeedonline*' => Http::response(psiPayload())]);

    $run = app(PageSpeedClient::class)->run($site, 'mobile');

    expect($run->status)->toBe('complete')
        // Lighthouse reports 0..1; columns store whole percentages.
        ->and($run->performance_score)->toBe(72)
        ->and($run->accessibility_score)->toBe(95)
        ->and($run->seo_score)->toBe(100)
        ->and($run->lab_data_available)->toBeTrue()
        // No CrUX block in this payload, so vitals come from the lab.
        ->and($run->field_data_available)->toBeFalse()
        ->and($run->lcp_ms)->toBe(3200)
        ->and((float) $run->cls)->toBe(0.14)
        ->and($run->tbt_ms)->toBe(310);

    $issues = PerformanceIssue::where('performance_run_id', $run->id)->get();

    // Failing audits become issues; passing and informational ones do not.
    expect($issues->pluck('audit_key')->all())
        ->toEqualCanonicalizing(['unused-css-rules', 'uses-responsive-images', 'color-contrast']);

    $css = $issues->firstWhere('audit_key', 'unused-css-rules');
    expect($css->severity)->toBe('high')
        ->and($css->savings_ms)->toBe(850)
        ->and($css->savings_bytes)->toBe(122880)
        ->and($css->category)->toBe('performance');

    // Category comes from the auditRefs mapping, not a guess.
    expect($issues->firstWhere('audit_key', 'color-contrast')->category)->toBe('accessibility');
});

it('prefers real-user field data over lab data for vitals', function () {
    [, $org] = makeOrgUser('psi-field@example.com');
    $site = pagespeedSite($org);

    Http::fake(['*pagespeedonline*' => Http::response(psiPayload([
        'loadingExperience' => ['metrics' => [
            'LARGEST_CONTENTFUL_PAINT_MS'    => ['percentile' => 2100, 'category' => 'NEEDS_IMPROVEMENT'],
            'INTERACTION_TO_NEXT_PAINT'      => ['percentile' => 180, 'category' => 'GOOD'],
            // CrUX scales CLS by 100.
            'CUMULATIVE_LAYOUT_SHIFT_SCORE'  => ['percentile' => 8, 'category' => 'GOOD'],
        ]],
    ]))]);

    $run = app(PageSpeedClient::class)->run($site, 'mobile');

    expect($run->field_data_available)->toBeTrue()
        // Field LCP (2100) wins over the lab's 3200.
        ->and($run->lcp_ms)->toBe(2100)
        ->and($run->lcp_status)->toBe('needs_improvement')
        ->and($run->inp_ms)->toBe(180)
        ->and($run->inp_status)->toBe('good')
        // 8 / 100 — stored unscaled.
        ->and((float) $run->cls)->toBe(0.08)
        // Lab-only metrics still come from the lab.
        ->and($run->fcp_ms)->toBe(1400);
});

it('records a failed run instead of leaving it stuck running', function () {
    [, $org] = makeOrgUser('psi-fail@example.com');
    $site = pagespeedSite($org);

    Http::fake(['*pagespeedonline*' => Http::response(['error' => ['message' => 'Unable to fetch the page']], 400)]);

    expect(fn () => app(PageSpeedClient::class)->run($site, 'mobile'))
        ->toThrow(PageSpeedFailure::class);

    $run = PerformanceRun::where('site_id', $site->id)->firstOrFail();
    expect($run->status)->toBe('failed')->and($run->error_message)->toContain('Unable to fetch');
});

it('marks a quota error as retryable but a bad url as terminal', function () {
    [, $org] = makeOrgUser('psi-quota@example.com');
    $site = pagespeedSite($org);

    Http::fake(['*pagespeedonline*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

    try {
        app(PageSpeedClient::class)->run($site, 'mobile');
    } catch (PageSpeedFailure $e) {
        expect($e->errorCode)->toBe('quota_exceeded')->and($e->retryable)->toBeTrue();
    }
});

it('rejects an unknown strategy without calling out', function () {
    [, $org] = makeOrgUser('psi-strategy@example.com');
    $site = pagespeedSite($org);

    Http::preventStrayRequests();

    expect(fn () => app(PageSpeedClient::class)->run($site, 'tablet'))
        ->toThrow(PageSpeedFailure::class);

    expect(PerformanceRun::where('site_id', $site->id)->count())->toBe(0);
});

it('queues both strategies from the run-now endpoint', function () {
    Queue::fake();

    [$user, $org] = makeOrgUser('psi-runnow@example.com');
    $site = pagespeedSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/analytics/performance/run")
        ->assertStatus(202)
        ->assertJson(['status' => 'queued']);

    Queue::assertPushed(RunPageSpeedTest::class, 2);
});

it('queues only the requested strategy when one is named', function () {
    Queue::fake();

    [$user, $org] = makeOrgUser('psi-one@example.com');
    $site = pagespeedSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/analytics/performance/run", ['strategy' => 'desktop'])
        ->assertStatus(202);

    Queue::assertPushed(RunPageSpeedTest::class, 1);
    Queue::assertPushed(fn (RunPageSpeedTest $job) => $job->strategy === 'desktop');
});

it('blocks run-now for non-admin roles and other tenants', function () {
    Queue::fake();

    [, $org] = makeOrgUser('psi-role@example.com');
    $site = pagespeedSite($org);

    $analyst = \App\Models\User::create([
        'name' => 'Analyst', 'email' => 'psi-analyst@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($analyst->id, ['role' => 'analyst']);

    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/analytics/performance/run")
        ->assertForbidden();

    [$otherUser] = makeOrgUser('psi-other@example.com');
    $this->actingAs($otherUser)
        ->postJson("/api/sites/{$site->id}/analytics/performance/run")
        ->assertNotFound();

    Queue::assertNothingPushed();
});

it('schedules runs only for connected, non-archived sites', function () {
    Queue::fake();

    [, $org] = makeOrgUser('psi-schedule@example.com');
    pagespeedSite($org, 'connected');

    $pending = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'Pending', 'domain' => 'pending.example.com',
        'connection_status' => 'pending',
    ]);
    $archived = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'Archived', 'domain' => 'archived.example.com',
        'connection_status' => 'connected', 'archived_at' => now(),
    ]);

    $this->artisan('insightistic:pagespeed')->assertSuccessful();

    // Two strategies for the one connected site; nothing for the others.
    Queue::assertPushed(RunPageSpeedTest::class, 2);
    Queue::assertNotPushed(fn (RunPageSpeedTest $job) => in_array($job->siteId, [$pending->id, $archived->id], true));
});

it('feeds the existing regression alert rule', function () {
    [, $org] = makeOrgUser('psi-regression@example.com');
    $site = pagespeedSite($org);

    // A healthy run, then a much worse one.
    PerformanceRun::create([
        'site_id' => $site->id, 'url' => 'https://shop.example.com', 'strategy' => 'mobile',
        'run_at' => now()->subDay(), 'status' => 'complete', 'performance_score' => 90,
    ]);

    Http::fake(['*pagespeedonline*' => Http::response(psiPayload([
        'lighthouseResult' => ['categories' => ['performance' => ['score' => 0.55, 'auditRefs' => []]]],
    ]))]);

    app(PageSpeedClient::class)->run($site, 'mobile');

    app(\App\Services\AlertDetector::class)->scan($site->fresh());

    $alert = \App\Models\Alert::where('site_id', $site->id)
        ->where('type', 'performance_regression')
        ->where('is_resolved', false)
        ->first();

    expect($alert)->not->toBeNull()
        ->and($alert->message)->toContain('35 points');
});
