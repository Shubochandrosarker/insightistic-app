<?php

use App\Models\AutomationRule;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Actions\ActionType;
use App\Services\Automation\AutomationDispatcher;
use Illuminate\Support\Facades\Mail;

function dispatcherSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Dispatcher Shop',
        'domain' => 'disp-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

function dispatcherRule(Organization $org, Site $site, array $overrides = []): AutomationRule
{
    return AutomationRule::create(array_merge([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'name' => 'Test Rule',
        'is_active' => true,
        'trigger_type' => 'alert_created',
        'trigger_config_json' => [],
        'action_type' => ActionType::SEND_NOTIFICATION_EMAIL,
        'action_params_json' => ['subject' => 'S', 'body' => 'B'],
    ], $overrides));
}

it('fires a matching rule and records a successful AutomationRun', function () {
    Mail::fake();
    [, $org] = makeOrgUser('dispatch-fire@example.com');
    $site = dispatcherSite($org);
    $rule = dispatcherRule($org, $site);

    $runs = app(AutomationDispatcher::class)->dispatch('alert_created', $site, ['alert_type' => 'revenue_drop', 'alert_id' => 1]);

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe('success')
        ->and($rule->fresh()->last_fired_at)->not->toBeNull()
        ->and($rule->fresh()->last_run_status)->toBe('success');
});

it('does not fire an inactive rule', function () {
    [, $org] = makeOrgUser('dispatch-inactive@example.com');
    $site = dispatcherSite($org);
    dispatcherRule($org, $site, ['is_active' => false]);

    $runs = app(AutomationDispatcher::class)->dispatch('alert_created', $site, ['alert_type' => 'revenue_drop']);

    expect($runs)->toBe([]);
});

it('filters alert_created by trigger_config alert_type when configured', function () {
    Mail::fake();
    [, $org] = makeOrgUser('dispatch-filter@example.com');
    $site = dispatcherSite($org);
    dispatcherRule($org, $site, ['trigger_config_json' => ['alert_type' => 'revenue_drop']]);

    $noMatch = app(AutomationDispatcher::class)->dispatch('alert_created', $site, ['alert_type' => 'refund_spike']);
    expect($noMatch)->toBe([]);

    $match = app(AutomationDispatcher::class)->dispatch('alert_created', $site, ['alert_type' => 'revenue_drop']);
    expect($match)->toHaveCount(1);
});

it('records a failed AutomationRun without throwing when the action fails', function () {
    [, $org] = makeOrgUser('dispatch-fail@example.com');
    $site = dispatcherSite($org);
    dispatcherRule($org, $site, [
        'action_type' => ActionType::RESOLVE_ALERT,
        'action_params_json' => ['alert_id' => 999999], // does not exist
    ]);

    $runs = app(AutomationDispatcher::class)->dispatch('alert_created', $site, ['alert_type' => 'revenue_drop']);

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe('failed')
        ->and($runs[0]->error_message)->toBe('alert_not_found');
});

it('only fires an hourly schedule rule once per hour', function () {
    Mail::fake();
    [, $org] = makeOrgUser('dispatch-schedule@example.com');
    $site = dispatcherSite($org);
    dispatcherRule($org, $site, ['trigger_type' => 'schedule', 'trigger_config_json' => ['frequency' => 'hourly']]);

    $first = app(AutomationDispatcher::class)->dispatch('schedule', $site, []);
    expect($first)->toHaveCount(1);

    $second = app(AutomationDispatcher::class)->dispatch('schedule', $site, []);
    expect($second)->toBe([]); // last_fired_at was just set — not due again yet
});

it('never fires a rule belonging to another site', function () {
    Mail::fake();
    [, $orgA] = makeOrgUser('dispatch-iso-a@example.com');
    [, $orgB] = makeOrgUser('dispatch-iso-b@example.com');
    $siteA = dispatcherSite($orgA);
    $siteB = dispatcherSite($orgB);
    dispatcherRule($orgA, $siteA);

    $runs = app(AutomationDispatcher::class)->dispatch('alert_created', $siteB, ['alert_type' => 'revenue_drop']);

    expect($runs)->toBe([]);
});

it('rate-limits repeated event dispatches with a per-rule cooldown (doc 19)', function () {
    // First dispatch fires; an immediate second dispatch — even for a
    // genuinely NEW alert — is suppressed by the 60-minute default cooldown.
    Mail::fake();
    [, $org] = makeOrgUser('dispatch-cooldown@example.com');
    $site = dispatcherSite($org);
    dispatcherRule($org, $site); // alert_created, empty config (matches any alert)

    $dispatcher = app(AutomationDispatcher::class);

    $first = $dispatcher->dispatch('alert_created', $site, ['alert_type' => 'revenue_drop', 'alert_id' => 1]);
    expect($first)->toHaveCount(1);

    $second = $dispatcher->dispatch('alert_created', $site, ['alert_type' => 'revenue_drop', 'alert_id' => 2]);
    expect($second)->toBe([]); // cooldown: the rule already fired moments ago
});

