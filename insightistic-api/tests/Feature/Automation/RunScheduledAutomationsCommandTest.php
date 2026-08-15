<?php

use App\Models\AutomationRule;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Support\Facades\Mail;

function commandSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Command Shop',
        'domain' => 'cmd-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

it('fires a due schedule rule and skips one already fired this hour', function () {
    Mail::fake();
    [, $org] = makeOrgUser('cmd-scheduled@example.com');
    $site = commandSite($org);

    $due = AutomationRule::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'name' => 'Due',
        'is_active' => true, 'trigger_type' => 'schedule', 'trigger_config_json' => ['frequency' => 'hourly'],
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
    ]);
    $notDue = AutomationRule::create([
        'organization_id' => $org->id, 'site_id' => $site->id, 'name' => 'Not due',
        'is_active' => true, 'trigger_type' => 'schedule', 'trigger_config_json' => ['frequency' => 'hourly'],
        'action_type' => 'send_notification_email', 'action_params_json' => ['subject' => 'S', 'body' => 'B'],
        'last_fired_at' => now(), // fired moments ago — not due again this hour
    ]);

    $this->artisan('insightistic:automation-run-scheduled')->assertSuccessful();

    expect($due->fresh()->last_fired_at)->not->toBeNull()
        ->and($notDue->runs()->count())->toBe(0)
        ->and($due->runs()->count())->toBe(1);
});
