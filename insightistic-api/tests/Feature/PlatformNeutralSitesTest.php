<?php

use App\Jobs\CrawlSiteJob;
use App\Jobs\DetectPlatformJob;
use App\Jobs\RunPageSpeedTest;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Services\LicenseService;
use Illuminate\Support\Facades\Queue;

/**
 * Platform-neutral site creation (2026-09-23 milestone): a URL is all a
 * customer needs — no license, no plugin — the site is generic by default,
 * duplicates are refused, and useful work starts immediately.
 */
it('creates a generic site from a bare URL and kicks off the first audit pipeline', function () {
    Queue::fake();
    [$user, $org] = makeOrgUser('pns-create@example.com');

    $response = $this->actingAs($user)->postJson('/api/sites', [
        'domain' => 'https://www.AnySite.com/',
    ])->assertCreated();

    expect($response->json('site.platform'))->toBe('generic')
        ->and($response->json('site.normalized_domain'))->toBe('anysite.com')
        ->and($response->json('site.status'))->toBe('active')
        ->and($response->json('kickoff'))->toContain('crawler', 'uptime', 'platform_detect');

    $site = Site::withoutGlobalScope('organization')->find($response->json('site.id'));
    expect($site->canonical_url)->toBe('https://www.anysite.com')
        ->and($site->resolvedPlatform())->toBe('generic')
        ->and($site->isWordPressFamily())->toBeFalse();

    Queue::assertPushed(DetectPlatformJob::class);
    Queue::assertPushed(CrawlSiteJob::class);
    expect(UptimeMonitor::where('site_id', $site->id)->where('active', true)->exists())->toBeTrue();
});

it('starts a PageSpeed run only when an API key is configured', function () {
    Queue::fake();
    config()->set('insightistic.pagespeed.api_key', null);
    [$user, $org] = makeOrgUser('pns-nops@example.com');

    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'nopagespeed.com'])
        ->assertCreated();

    Queue::assertNotPushed(RunPageSpeedTest::class);

    config()->set('insightistic.pagespeed.api_key', 'k-test');
    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'withpagespeed.com'])
        ->assertCreated();

    Queue::assertPushed(RunPageSpeedTest::class);
});

it('refuses duplicate domains across equivalent spellings within the org', function () {
    Queue::fake();
    [$user, $org] = makeOrgUser('pns-dupe@example.com');

    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'example.com'])->assertCreated();

    $second = $this->actingAs($user)->postJson('/api/sites', ['domain' => 'https://www.example.com/']);
    $second->assertStatus(409)->assertJsonPath('code', 'duplicate_site');

    $existingId = Site::withoutGlobalScope('organization')->where('normalized_domain', 'example.com')->first()->id;
    expect($second->json('site.id'))->toBe($existingId);

    expect(Site::withoutGlobalScope('organization')->where('normalized_domain', 'example.com')->count())->toBe(1);
});

it('still enforces the plan site limit before anything else', function () {
    Queue::fake();
    [$user, $org] = makeOrgUser('pns-limit@example.com');

    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'one.com'])->assertCreated();
    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'two.com'])->assertCreated();
    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'three.com'])->assertCreated();
    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'four.com'])->assertCreated();
    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'five.com'])->assertCreated();

    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'six.com'])
        ->assertStatus(402)->assertJsonPath('code', 'site_limit_reached');
});

it('rejects malformed domains with 422 instead of creating garbage', function () {
    [$user, $org] = makeOrgUser('pns-invalid@example.com');

    $this->actingAs($user)->postJson('/api/sites', ['domain' => 'not a url'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_domain');
});

it('plugin activation links to an existing URL-created site instead of duplicating it', function () {
    Queue::fake();
    [$user, $org] = makeOrgUser('pns-link@example.com');

    $created = $this->actingAs($user)->postJson('/api/sites', ['domain' => 'https://myshop.io'])->assertCreated()->json('site.id');

    // A license activation arriving later for the same domain (www variant)
    // must reuse that site rather than create a second one.
    $licenseKey = app(LicenseService::class)->issue($org);

    $this->postJson('/api/plugin/activate', [
        'license_key' => $licenseKey,
        'site_url' => 'https://www.myshop.io',
        'site_name' => 'My Shop',
        'wp_version' => '6.5',
        'woocommerce_active' => false,
    ])->assertOk();

    expect(Site::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(1)
        ->and(Site::withoutGlobalScope('organization')->find($created)->platform)->toBe('wordpress');
});
