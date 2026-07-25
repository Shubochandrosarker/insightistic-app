<?php

use App\Models\License;
use App\Models\Site;
use App\Services\LicenseService;
use Illuminate\Support\Facades\DB;

/**
 * Guided setup (spec §9).
 *
 * The point of these tests is that progress is *derived*: completing a step
 * out-of-band must be reflected, and undoing one must un-complete it. A
 * stored cursor would pass the first and fail the second.
 */

it('reports every step incomplete for a brand-new organization', function () {
    [$user] = makeOrgUser('onb-new@example.com');

    $body = $this->actingAs($user)->getJson('/api/onboarding')->assertOk()->json();

    expect($body['total'])->toBe(8)
        // Creating the organization is the one step already done by signing up.
        ->and($body['completed'])->toBe(1)
        ->and($body['finished'])->toBeFalse()
        ->and($body['next'])->toBe('site');
});

it('derives progress from real state as steps are completed', function () {
    [$user, $org] = makeOrgUser('onb-progress@example.com');

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'Shop', 'domain' => 'shop.example.com',
    ]);

    expect($this->actingAs($user)->getJson('/api/onboarding')->json('next'))->toBe('license');

    app(LicenseService::class)->issue($org->fresh());
    expect($this->actingAs($user)->getJson('/api/onboarding')->json('next'))->toBe('plugin');

    $site->forceFill(['connection_status' => 'connected'])->save();
    expect($this->actingAs($user)->getJson('/api/onboarding')->json('next'))->toBe('sync');
});

it('un-completes a step when the underlying state goes away', function () {
    [$user, $org] = makeOrgUser('onb-regress@example.com');

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'Shop', 'domain' => 'shop.example.com',
    ]);

    expect($this->actingAs($user)->getJson('/api/onboarding')->json('next'))->toBe('license');

    // Deleting the site must walk the checklist back — a stored cursor would
    // keep claiming the step was done.
    $site->delete();

    expect($this->actingAs($user)->getJson('/api/onboarding')->json('next'))->toBe('site');
});

it('remembers a dismissal and can restore it', function () {
    [$user, $org] = makeOrgUser('onb-dismiss@example.com');

    expect($this->actingAs($user)->getJson('/api/onboarding')->json('dismissed'))->toBeFalse();

    $this->actingAs($user)->postJson('/api/onboarding/dismiss')->assertOk();
    expect($this->actingAs($user)->getJson('/api/onboarding')->json('dismissed'))->toBeTrue();

    $this->actingAs($user)->deleteJson('/api/onboarding/dismiss')->assertOk();
    expect($this->actingAs($user)->getJson('/api/onboarding')->json('dismissed'))->toBeFalse();
});

it('keeps another organization\'s progress out of the response', function () {
    [$userA] = makeOrgUser('onb-a@example.com');
    [, $orgB] = makeOrgUser('onb-b@example.com');

    Site::withoutGlobalScope('organization')->create([
        'organization_id' => $orgB->id, 'name' => 'Other', 'domain' => 'other.example.com',
    ]);

    // B has a site; A must not inherit that progress.
    expect($this->actingAs($userA)->getJson('/api/onboarding')->json('next'))->toBe('site');
});
