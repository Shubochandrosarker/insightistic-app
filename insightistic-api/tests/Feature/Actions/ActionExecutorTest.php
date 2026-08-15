<?php

use App\Models\AiInsight;
use App\Models\Alert;
use App\Models\Organization;
use App\Models\SavedInsight;
use App\Models\Site;
use App\Services\Actions\ActionExecutor;
use App\Services\Actions\ActionType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function executorSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Executor Shop',
        'domain' => 'exec-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

it('resolves an alert scoped to the site, and 404-equivalents for a foreign one', function () {
    [, $org] = makeOrgUser('exec-resolve-alert@example.com');
    $site = executorSite($org);
    $otherSite = executorSite($org);
    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);
    $foreignAlert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $otherSite->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);

    $result = app(ActionExecutor::class)->execute(ActionType::RESOLVE_ALERT, ['alert_id' => $alert->id], $site, null);
    expect($result['success'])->toBeTrue();
    expect($alert->fresh()->is_resolved)->toBeTrue();

    $result = app(ActionExecutor::class)->execute(ActionType::RESOLVE_ALERT, ['alert_id' => $foreignAlert->id], $site, null);
    expect($result['success'])->toBeFalse()->and($result['error'])->toBe('alert_not_found');
    expect($foreignAlert->fresh()->is_resolved)->toBeFalse();
});

it('dismisses an insight scoped to the site', function () {
    [, $org] = makeOrgUser('exec-dismiss-insight@example.com');
    $site = executorSite($org);
    $insight = AiInsight::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'type' => 'traffic',
        'title' => 'x', 'summary' => 'y', 'severity' => 'low', 'priority_score' => 3, 'status' => 'unread',
    ]);

    $result = app(ActionExecutor::class)->execute(ActionType::DISMISS_INSIGHT, ['insight_id' => $insight->id], $site, null);

    expect($result['success'])->toBeTrue()
        ->and($insight->fresh()->dismissed_at)->not->toBeNull();
});

it('creates a saved insight from a valid plan and rejects an invalid one', function () {
    [$user, $org] = makeOrgUser('exec-create-saved-insight@example.com');
    $site = executorSite($org);

    $result = app(ActionExecutor::class)->execute(ActionType::CREATE_SAVED_INSIGHT, [
        'name' => 'From Automation',
        'plan' => ['metrics' => ['net_revenue']],
    ], $site, $user->id);

    expect($result['success'])->toBeTrue();
    $saved = SavedInsight::find($result['saved_insight_id']);
    expect($saved->name)->toBe('From Automation')->and($saved->site_id)->toBe($site->id);

    $bad = app(ActionExecutor::class)->execute(ActionType::CREATE_SAVED_INSIGHT, [
        'name' => 'Bad',
        'plan' => ['metrics' => ['not_a_real_metric']],
    ], $site, $user->id);
    expect($bad['success'])->toBeFalse()->and($bad['error'])->toBe('unknown_metric');
});

it('sends a notification email to the organization owner', function () {
    Mail::fake();
    [, $org] = makeOrgUser('exec-notify@example.com');
    $site = executorSite($org);

    $result = app(ActionExecutor::class)->execute(ActionType::SEND_NOTIFICATION_EMAIL, [
        'subject' => 'Heads up', 'body' => 'Something happened.',
    ], $site, null);

    expect($result['success'])->toBeTrue()->and($result['recipient'])->toBe($org->owner->email);
    // AutomationNotification implements ShouldQueue, so Mail::fake() tracks
    // it as queued rather than immediately sent — same as LifecycleMail.
    Mail::assertQueued(\App\Mail\AutomationNotification::class, fn ($mail) => $mail->subjectLine === 'Heads up' && $mail->bodyText === 'Something happened.');
});

it('rejects an unsafe webhook URL without making any HTTP call', function () {
    Http::fake();
    [, $org] = makeOrgUser('exec-webhook-unsafe@example.com');
    $site = executorSite($org);

    $result = app(ActionExecutor::class)->execute(ActionType::SEND_WEBHOOK, [
        'url' => 'http://169.254.169.254/latest/meta-data/', 'payload' => ['x' => 1],
    ], $site, null);

    expect($result['success'])->toBeFalse()->and($result['error'])->toBe('unsafe_url');
    Http::assertNothingSent();
});

it('delivers a signed webhook to a safe URL', function () {
    Http::fake(['https://example.com/*' => Http::response(['ok' => true], 200)]);
    [, $org] = makeOrgUser('exec-webhook-ok@example.com');
    $site = executorSite($org);

    $result = app(ActionExecutor::class)->execute(ActionType::SEND_WEBHOOK, [
        'url' => 'https://example.com/hook', 'payload' => ['metric' => 'net_revenue', 'value' => 500],
    ], $site, null, 'shh-secret');

    expect($result['success'])->toBeTrue()->and($result['status'])->toBe(200);

    Http::assertSent(function ($request) {
        expect($request->hasHeader('X-Insightistic-Signature'))->toBeTrue()
            ->and($request->hasHeader('X-Insightistic-Timestamp'))->toBeTrue();
        $body = json_decode($request->body(), true);

        return $body['data']['metric'] === 'net_revenue' && $body['site_id'] !== null;
    });
});
