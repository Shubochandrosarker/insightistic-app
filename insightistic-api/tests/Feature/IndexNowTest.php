<?php

use App\Models\IndexNowKey;
use App\Models\IndexNowSubmission;
use App\Models\Site;
use App\Services\IndexNow\IndexNowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * IndexNow tenant surface (Phase 6, docs/roadmap-2026/11-BING-WEBMASTER-
 * INDEXNOW §IndexNow): key lifecycle, manual submission, dedupe, provider
 * error classification, role gating and payload validation.
 */

function indexnowSite(\App\Models\Organization $org, string $domain = 'shop.example.com'): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => 'Store',
        'domain'            => $domain,
        'connection_status' => 'connected',
    ]);
}

function indexnowMember(\App\Models\Organization $org, string $email, string $role): \App\Models\User
{
    $user = \App\Models\User::create([
        'name' => ucfirst($role) . ' ' . $email, 'email' => $email,
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($user->id, ['role' => $role]);

    return $user;
}

it('lazily creates a key on submit and only rotates it on regenerate', function () {
    [$user, $org] = makeOrgUser('indexnow-key@example.com');
    $site = indexnowSite($org);

    // No key yet — status is honest about it.
    $this->actingAs($user)->getJson("/api/sites/{$site->id}/indexnow")
        ->assertOk()
        ->assertJsonPath('status.key', null)
        ->assertJsonPath('status.key_file_confirmed', false);

    // Manual submit auto-initializes the key (plugin setup may never have run).
    Http::fake(['*api.indexnow.org*' => Http::response([], 200)]);
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['https://shop.example.com/hello-world'],
    ])->assertOk()->assertJsonPath('accepted', 1);

    expect(IndexNowKey::where('site_id', $site->id)->count())->toBe(1);

    $first = IndexNowKey::where('site_id', $site->id)->firstOrFail();
    expect($first->key)->toMatch('/^[0-9a-f]{32}$/');

    // Reading status never rotates the key.
    $this->actingAs($user)->getJson("/api/sites/{$site->id}/indexnow")
        ->assertOk()->assertJsonPath('status.key', $first->key);

    // Owner regenerate forces a NEW key.
    $regen = $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/regenerate-key")
        ->assertOk()->assertJsonPath('status.key_file_confirmed', false)
        ->json('status.key');

    expect($regen)->not->toBe($first->key)
        ->and(IndexNowKey::where('site_id', $site->id)->count())->toBe(1);
});

it('resets the key file confirmation when the key is regenerated', function () {
    [$user, $org] = makeOrgUser('indexnow-confirm@example.com');
    $site = indexnowSite($org);
    $service = app(IndexNowService::class);

    $service->generateKey($site);
    $service->confirmKeyFile($site);
    expect((bool) IndexNowKey::where('site_id', $site->id)->value('key_file_confirmed'))->toBeTrue();

    $service->regenerateKey($site);

    expect((bool) IndexNowKey::where('site_id', $site->id)->value('key_file_confirmed'))->toBeFalse();
});

it('submits urls, logs them, and sets 24h dedupe markers', function () {
    [$user, $org] = makeOrgUser('indexnow-happy@example.com');
    $site = indexnowSite($org);
    app(IndexNowService::class)->generateKey($site);

    Http::fake(['*api.indexnow.org*' => Http::response([], 200)]);

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => [
            'https://shop.example.com/a',
            'https://SHOP.example.com/b?variant=2#top', // host case + fragment normalized
        ],
    ])->assertOk()->assertJson([
        'submitted' => 2, 'accepted' => 2, 'failed' => 0,
        'skipped_duplicate' => 0, 'skipped_invalid_host' => 0,
    ]);

    // One batch POST carrying both normalized URLs.
    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($site) {
        $body = $request->data();

        return str_contains($request->url(), 'api.indexnow.org')
            && $body['host'] === 'shop.example.com'
            && isset($body['key'], $body['keyLocation'])
            && $body['urlList'] === ['https://shop.example.com/a', 'https://shop.example.com/b?variant=2'];
    });

    $rows = IndexNowSubmission::where('site_id', $site->id)->get();
    expect($rows->where('status', 'accepted')->count())->toBe(2)
        ->and($rows->firstWhere('url', 'https://shop.example.com/a')->source)->toBe('manual')
        ->and($rows->firstWhere('url', 'https://shop.example.com/a')->http_status)->toBe(200)
        ->and($rows->whereNotNull('batch_id')->count())->toBe(2);

    // Accepted URLs carry the dedupe marker.
    foreach ($rows->where('status', 'accepted') as $row) {
        expect(Cache::has("indexnow:{$site->id}:{$row->url_hash}"))->toBeTrue();
    }
});

it('skips urls already submitted within the dedupe window', function () {
    [$user, $org] = makeOrgUser('indexnow-dedupe@example.com');
    $site = indexnowSite($org);
    app(IndexNowService::class)->generateKey($site);

    Http::fake(['*api.indexnow.org*' => Http::response([], 200)]);
    $urls = ['urls' => ['https://shop.example.com/a', 'https://shop.example.com/b']];

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", $urls)
        ->assertOk()->assertJsonPath('accepted', 2);

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", $urls)
        ->assertOk()->assertJson([
            'submitted' => 0, 'accepted' => 0, 'failed' => 0,
            'skipped_duplicate' => 2, 'skipped_invalid_host' => 0,
        ]);

    // Second call sent nothing and logged skips without a second accepted pair.
    Http::assertSentCount(1);
    expect(IndexNowSubmission::where('site_id', $site->id)->where('status', 'accepted')->count())->toBe(2)
        ->and(IndexNowSubmission::where('site_id', $site->id)->where('status', 'skipped_duplicate')->count())->toBe(2);
});

it('allows resubmission once the dedupe window expires', function () {
    [$user, $org] = makeOrgUser('indexnow-expiry@example.com');
    $site = indexnowSite($org);
    app(IndexNowService::class)->generateKey($site);

    Http::fake(['*api.indexnow.org*' => Http::response([], 200)]);
    $urls = ['urls' => ['https://shop.example.com/a']];

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", $urls)
        ->assertOk()->assertJsonPath('accepted', 1);

    $this->travel(23)->hours();
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", $urls)
        ->assertOk()->assertJsonPath('skipped_duplicate', 1);

    $this->travel(2)->hours(); // past the 24h window
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", $urls)
        ->assertOk()->assertJson(['submitted' => 1, 'accepted' => 1, 'skipped_duplicate' => 0]);
});

it('classifies a 403 key failure as permanent and a 429 as retryable', function () {
    [$user, $org] = makeOrgUser('indexnow-errors@example.com');
    $site = indexnowSite($org);
    app(IndexNowService::class)->generateKey($site);

    // One fake, two ordered responses — a second Http::fake() call would NOT
    // replace the first stub within the same test.
    Http::fake(['*api.indexnow.org*' => Http::sequence()->push('', 403)->push('', 429)]);

    // 403: key file missing/mismatch — resubmitting changes nothing.
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['https://shop.example.com/permanent'],
    ])->assertOk()->assertJson(['failed' => 1, 'accepted' => 0]);

    $permanent = IndexNowSubmission::where('site_id', $site->id)->where('status', 'failed')->firstOrFail();
    expect($permanent->retryable)->toBeFalse()
        ->and($permanent->http_status)->toBe(403)
        ->and($permanent->error)->toContain('403');

    // 429: rate limited — worth retrying.
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['https://shop.example.com/throttled'],
    ])->assertOk()->assertJson(['failed' => 1, 'accepted' => 0]);

    $throttled = IndexNowSubmission::where('site_id', $site->id)->where('url', 'like', '%throttled')->firstOrFail();
    expect($throttled->retryable)->toBeTrue()
        ->and($throttled->error)->toContain('429');
});

it('keeps the key out of stored error messages', function () {
    [$user, $org] = makeOrgUser('indexnow-leak@example.com');
    $site = indexnowSite($org);
    $key = app(IndexNowService::class)->generateKey($site)->key;

    Http::fake(['*api.indexnow.org*' => Http::response('', 500)]);
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['https://shop.example.com/x'],
    ])->assertOk()->assertJsonPath('failed', 1);

    $row = IndexNowSubmission::where('site_id', $site->id)->firstOrFail();
    expect($row->retryable)->toBeTrue()->and($row->error)->not->toContain($key);
});

it('skips urls that do not belong to the site host', function () {
    [$user, $org] = makeOrgUser('indexnow-host@example.com');
    $site = indexnowSite($org);
    app(IndexNowService::class)->generateKey($site);

    Http::fake(['*api.indexnow.org*' => Http::response([], 200)]);

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => [
            'https://shop.example.com/mine',
            'https://evil.example.com/steal',
            'http://shop.example.com/insecure', // scheme is forced to https
        ],
    ])->assertOk()->assertJson([
        'submitted' => 2, 'accepted' => 2,
        'skipped_invalid_host' => 1, 'skipped_duplicate' => 0,
    ]);

    expect(IndexNowSubmission::where('site_id', $site->id)->count())->toBe(2)
        ->and(IndexNowSubmission::where('site_id', $site->id)->where('url', 'like', '%evil%')->count())->toBe(0)
        ->and(IndexNowSubmission::where('site_id', $site->id)->where('url', 'https://shop.example.com/insecure')->count())->toBe(1);
});

it('counts an unreachable provider as a retryable failure without erroring the request', function () {
    [$user, $org] = makeOrgUser('indexnow-unreach@example.com');
    $site = indexnowSite($org);
    app(IndexNowService::class)->generateKey($site);

    Http::fake(['*api.indexnow.org*' => Http::failedConnection('cURL error 28: Connection timed out')]);

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['https://shop.example.com/a', 'https://shop.example.com/b'],
    ])->assertOk()->assertJson(['failed' => 2, 'accepted' => 0]);

    $rows = IndexNowSubmission::where('site_id', $site->id)->where('status', 'failed')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($r) => $r->retryable))->toBeTrue()
        ->and($rows->pluck('error')->unique()->first())->toContain('unreachable');
});

it('gates manual submit and key regeneration by role', function () {
    [, $org] = makeOrgUser('indexnow-roles@example.com');
    $site = indexnowSite($org);

    $analyst = indexnowMember($org, 'indexnow-analyst@example.com', 'analyst');
    $admin = indexnowMember($org, 'indexnow-admin@example.com', 'admin');
    [$outsider] = makeOrgUser('indexnow-outsider@example.com');

    // Analyst: can read, cannot submit or rotate.
    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/indexnow")->assertOk();
    $this->actingAs($analyst)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['https://shop.example.com/a'],
    ])->assertForbidden()->assertJsonPath('code', 'insufficient_role');
    $this->actingAs($analyst)->postJson("/api/sites/{$site->id}/indexnow/regenerate-key")->assertForbidden();

    // Admin: submit allowed, regenerate not.
    Http::fake(['*api.indexnow.org*' => Http::response([], 200)]);
    $this->actingAs($admin)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['https://shop.example.com/b'],
    ])->assertOk()->assertJsonPath('accepted', 1);
    $this->actingAs($admin)->postJson("/api/sites/{$site->id}/indexnow/regenerate-key")->assertForbidden();

    // Another org's owner: the site simply does not exist for them.
    $this->actingAs($outsider)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['https://shop.example.com/c'],
    ])->assertNotFound();

    expect(IndexNowSubmission::where('site_id', $site->id)->count())->toBe(1);
});

it('validates the submit payload', function () {
    [$user, $org] = makeOrgUser('indexnow-validate@example.com');
    $site = indexnowSite($org);
    Http::fake(['*api.indexnow.org*' => Http::response([], 200)]);

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [])->assertStatus(422);
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => 'https://shop.example.com/not-an-array',
    ])->assertStatus(422);
    // Unknown element type (number instead of URL string).
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => [42],
    ])->assertStatus(422);
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['not a url at all'],
    ])->assertStatus(422);
    // Protocol cap: at most 1000 URLs per call.
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => array_fill(0, 1001, 'https://shop.example.com/page'),
    ])->assertStatus(422);

    expect(IndexNowSubmission::count())->toBe(0);
});

it('returns status plus recent submission history', function () {
    [$user, $org] = makeOrgUser('indexnow-history@example.com');
    $site = indexnowSite($org);
    $key = app(IndexNowService::class)->generateKey($site);

    Http::fake(['*api.indexnow.org*' => Http::response([], 200)]);
    $this->actingAs($user)->postJson("/api/sites/{$site->id}/indexnow/submit", [
        'urls' => ['https://shop.example.com/a', 'https://shop.example.com/b'],
    ])->assertOk();

    $response = $this->actingAs($user)->getJson("/api/sites/{$site->id}/indexnow")->assertOk();

    expect($response->json('status.key'))->toBe($key->key)
        ->and($response->json('status.key_location'))->toBe('https://shop.example.com/' . $key->key . '.txt')
        ->and($response->json('status.counts.accepted'))->toBe(2)
        ->and($response->json('status.last_submitted_at'))->not->toBeNull()
        ->and($response->json('submissions'))->toHaveCount(2)
        ->and(collect($response->json('submissions'))->pluck('url')->toArray())
            ->toEqualCanonicalizing(['https://shop.example.com/a', 'https://shop.example.com/b']);
});
