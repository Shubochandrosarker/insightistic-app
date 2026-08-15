<?php

use App\Models\AutomationRule;
use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Actions\ActionType;
use App\Services\AlertDetector;
use Illuminate\Support\Facades\Mail;

/**
 * Proves AlertDetector actually fires the 'alert_created' Automation
 * Studio trigger end to end — not just that AutomationDispatcher works in
 * isolation (see AutomationDispatcherTest.php).
 */
function wiringSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Wiring Shop',
        'domain' => 'wiring-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

it('fires an alert_created automation rule when AlertDetector raises a genuinely new alert', function () {
    Mail::fake();
    [, $org] = makeOrgUser('wiring-fire@example.com');
    $site = wiringSite($org);

    AutomationRule::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'name' => 'Notify on revenue drop',
        'is_active' => true, 'trigger_type' => 'alert_created',
        'trigger_config_json' => ['alert_type' => 'revenue_drop'],
        'action_type' => ActionType::SEND_NOTIFICATION_EMAIL,
        'action_params_json' => ['subject' => 'Revenue dropped', 'body' => 'Check the dashboard.'],
    ]);

    // Big drop vs the previous 7-day window — trips AlertDetector's revenue_drop rule.
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->subDays(10)->toDateString(), 'revenue' => 1000, 'orders' => 10, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 1, 'failed_orders' => 0]);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);

    app(AlertDetector::class)->scan($site);

    Mail::assertQueued(\App\Mail\AutomationNotification::class, fn ($mail) => $mail->subjectLine === 'Revenue dropped');
});

it('does not re-fire on a subsequent scan while the alert is still open (dedup)', function () {
    Mail::fake();
    [, $org] = makeOrgUser('wiring-dedup@example.com');
    $site = wiringSite($org);

    // Scoped to revenue_drop specifically — AlertDetector::scan() runs
    // several unrelated rules per call, some of which can also trip on
    // this deliberately sparse fixture (e.g. sync_stale, with no SyncLog
    // row at all); scoping the trigger avoids the count below being
    // muddied by those unrelated, legitimately-once-each firings.
    $rule = AutomationRule::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'name' => 'Notify',
        'is_active' => true, 'trigger_type' => 'alert_created',
        'trigger_config_json' => ['alert_type' => 'revenue_drop'],
        'action_type' => ActionType::SEND_NOTIFICATION_EMAIL,
        'action_params_json' => ['subject' => 'S', 'body' => 'B'],
    ]);

    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->subDays(10)->toDateString(), 'revenue' => 1000, 'orders' => 10, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 1, 'failed_orders' => 0]);
    MetricSnapshot::create(['site_id' => $site->id, 'date' => now()->toDateString(), 'revenue' => 100, 'orders' => 1, 'refunds' => 0, 'average_order_value' => 100, 'new_customers' => 1, 'returning_customers' => 0, 'products_sold' => 1, 'failed_orders' => 0]);

    $detector = app(AlertDetector::class);
    $detector->scan($site);
    $detector->scan($site); // same open alert — must not fire the automation again

    expect($rule->runs()->count())->toBe(1);
});
