<?php

use App\Domain\Billing\CreditWallet;
use App\Domain\Billing\EntitlementResolver;
use App\Models\AppSumoLicense;
use App\Models\ClientReport;
use App\Models\CreditLedgerEntry;
use App\Models\Plan;
use App\Models\ReportSchedule;
use App\Models\Site;
use App\Models\User;
use App\Services\UsageService;
use App\Services\ReportBuilder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Mail;

it('applies the revised AppSumo caps to existing plan rows', function () {
    $this->seed(PlanSeeder::class);
    [, $org] = makeOrgUser('appsumo-report-limits@example.com');
    $org->update(['billing_provider' => 'license']);
    $migration = require database_path('migrations/2026_11_19_000003_update_appsumo_reports_and_team_limits.php');
    $migration->down();
    $migration->up();
    $usage = app(UsageService::class);

    foreach ([10, 50, null, null, null] as $index => $reportLimit) {
        $tier = $index + 1;
        $plan = Plan::where('slug', "appsumo_tier_{$tier}")->firstOrFail();
        $org->update(['plan_id' => $plan->id]);
        $org->unsetRelation('plan');
        $usage->counter($org)->update(['reports_generated' => $reportLimit ?? 1000]);

        expect($usage->reportLimit($org))->toBe($reportLimit)
            ->and($usage->reportRemaining($org))->toBe($reportLimit === null ? null : 0)
            ->and($usage->canGenerateReport($org))->toBe($reportLimit === null)
            ->and($usage->scheduledReportLimit($org))->toBe([5, 25, 50, 100, null][$index])
            ->and($usage->connectedSiteUsage($org)['limit'])->toBe([5, 10, 15, 30, 50][$index])
            ->and($usage->limit($org, 'knowledge_bases'))->toBe([1, 3, 5, 10, 20][$index])
            ->and(in_array('executive_insights', $plan->features_json, true))->toBe($tier >= 4)
            ->and(in_array('priority_support', $plan->features_json, true))->toBe($tier >= 4)
            ->and(in_array('white_label_reports', $plan->features_json, true))->toBe($tier >= 4);
    }
});

it('charges 50 credits for each of five added teammates and blocks duplicates and a sixth', function () {
    Mail::fake();
    $this->seed(PlanSeeder::class);
    [$owner, $org] = makeOrgUser('appsumo-teams@example.com');
    $org->update([
        'plan_id' => Plan::where('slug', 'appsumo_tier_1')->value('id'),
        'billing_provider' => 'license',
    ]);
    $this->actingAs($owner)->getJson('/api/organizations/users')
        ->assertOk()->assertJsonPath('added_member_limit', 5)->assertJsonPath('member_add_credit_cost', 50);

    $invite = fn (int $member) => $this->postJson('/api/organizations/users/invite', [
        'email' => "appsumo-teammate-{$member}@example.com", 'role' => 'analyst',
    ]);
    for ($member = 1; $member <= 5; $member++) {
        $invite($member)->assertCreated();
    }
    $invite(1)->assertConflict();
    $invite(6)->assertStatus(402)->assertJsonPath('code', 'appsumo_team_member_limit_reached');

    expect($org->users()->count())->toBe(6)
        ->and(User::where('email', 'appsumo-teammate-6@example.com')->exists())->toBeFalse()
        ->and((int) CreditLedgerEntry::where('organization_id', $org->id)
            ->where('reason', 'team_member_added')->sum('amount_signed'))->toBe(-250)
        ->and(app(CreditWallet::class)->available($org))->toBe(4750);
});

it('preserves early Tier 3 license features while keeping the new Tier 3 terms', function () {
    $this->seed(PlanSeeder::class);
    [, $early] = makeOrgUser('appsumo-early@example.com');
    [, $new] = makeOrgUser('appsumo-new@example.com');
    $tierThree = Plan::where('slug', 'appsumo_tier_3')->firstOrFail();
    $early->update(['plan_id' => $tierThree->id, 'billing_provider' => 'license']);
    $new->update(['plan_id' => $tierThree->id, 'billing_provider' => 'license']);
    $migration = require database_path('migrations/2026_11_19_000003_update_appsumo_reports_and_team_limits.php');
    $migration->down();
    $license = AppSumoLicense::create([
        'license_key' => 'early-tier-three', 'event' => 'activate', 'license_status' => 'active',
        'tier' => 3, 'org_id' => $early->id, 'plan_slug' => $tierThree->slug, 'is_test' => false,
    ]);
    $migration->up();

    $entitlements = app(EntitlementResolver::class);
    expect($entitlements->resolve($early)['features'])->toContain('white_label_reports', 'priority_support')
        ->and($entitlements->resolve($new)['features'])->not->toContain('white_label_reports', 'priority_support');

    $license->update(['license_status' => 'deactivated']);
    expect($entitlements->resolve($early)['features'])->not->toContain('white_label_reports', 'priority_support');
});

it('allows multiple scheduled deliveries for the same project and frequency', function () {
    Mail::fake();
    $this->travelTo(now()->startOfWeek()->setTime(9, 0));
    $this->seed(PlanSeeder::class);
    [$owner, $org] = makeOrgUser('appsumo-schedules@example.com');
    $org->update([
        'plan_id' => Plan::where('slug', 'appsumo_tier_5')->value('id'),
        'billing_provider' => 'license',
    ]);
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'Schedule Test',
        'domain' => 'schedules.example.com', 'normalized_domain' => 'schedules.example.com',
        'platform' => 'generic', 'timezone' => 'UTC',
    ]);
    for ($index = 0; $index < 100; $index++) {
        ReportSchedule::create([
            'site_id' => $site->id, 'frequency' => 'monthly', 'send_day' => '28',
            'recipients' => "monthly-{$index}@example.com", 'is_active' => true,
        ]);
    }

    foreach (['first@example.com', 'second@example.com'] as $recipient) {
        $this->actingAs($owner)->postJson("/api/sites/{$site->id}/report-schedules", [
            'frequency' => 'weekly', 'send_day' => 'monday', 'send_time' => '09:00',
            'timezone' => 'UTC', 'recipients' => $recipient,
        ])->assertCreated();
    }

    $builder = $this->mock(ReportBuilder::class);
    $builder->shouldReceive('generate')->twice()->andReturnUsing(fn (Site $project, string $type) => ClientReport::create([
        'site_id' => $project->id, 'organization_id' => $org->id, 'title' => 'Scheduled report',
        'report_type' => $type, 'period_start' => now()->subWeek()->toDateString(),
        'period_end' => now()->toDateString(), 'html_url' => 'test.html', 'pdf_url' => 'test.pdf',
    ]));
    $builder->shouldReceive('aiSummaryGenerated')->twice()->andReturn(false);

    $this->artisan('insightistic:reports-deliver')->assertSuccessful();
    $this->artisan('insightistic:reports-deliver')->assertSuccessful();

    expect(ClientReport::whereNotNull('report_schedule_id')->count())->toBe(2)
        ->and(app(UsageService::class)->scheduledReportsUsed($org))->toBe(102);
});
