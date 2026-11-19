<?php

use App\Domain\Billing\AiAllowanceContext;
use App\Models\AiAllowanceReservation;
use App\Models\AiInsight;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\PerformanceRun;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTierResolver;
use App\Services\Ai\PlanAwareAiProvider;
use Illuminate\Support\Str;

/**
 * End-to-end wiring of the AI allowance: the controller arms the tariff scope,
 * the provider chain holds the cost before the paid model runs, and the answer
 * is settled (or not) exactly once. Compared with AiAllowanceGuardTest — which
 * pins the guard's own contract — these tests prove the real HTTP entry point
 * uses it and that a short balance still returns an insight.
 */
class AllowanceFlowProvider implements AiProvider
{
    public int $calls = 0;

    public function __construct(private string $label, private ?array $result) {}

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        $this->calls++;

        return $this->result;
    }

    public function model(): string
    {
        return $this->label;
    }
}

/** Paid tier (priced plan + active subscription) carrying the plan's AI features. */
function paidAllowanceFlowOrg(string $email, array $planLimits): array
{
    [$user, $org] = makeOrgUser($email, $planLimits);

    $plan = Plan::create([
        'name' => 'Growth',
        'slug' => 'growth-flow-' . Str::random(8),
        'price_monthly' => 6900,
        'site_limit' => 10,
        'user_limit' => 3,
        'ai_insight_limit' => 100,
        'report_limit' => 20,
        'features_json' => ['ai_insights', 'seo_analytics', 'advanced_reports'],
        'limits_json' => $planLimits,
        'is_active' => true,
    ]);

    Subscription::create([
        'organization_id' => $org->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    return [$user, $org];
}

function allowanceFlowSite(Organization $org): Site
{
    $domain = 'allowance-' . uniqid() . '.example.com';

    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Allowance Shop',
        'domain' => $domain,
        'normalized_domain' => $domain,
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

function insightPayload(string $tag): array
{
    return [
        'title' => 'Insight ' . $tag,
        'summary' => 'Summary ' . $tag,
        'possible_reason' => 'Because of ' . $tag,
        'recommendation' => 'Do the ' . $tag . ' thing',
        'severity' => 'medium',
        'priority_score' => 5,
    ];
}

it('serves a paid insight from the free chain, uncharged, when the allowance is short', function () {
    [$user, $org] = paidAllowanceFlowOrg('allowance-flow-short@example.com', ['monthly_ai_credits' => 0]);
    $site = allowanceFlowSite($org);

    $paid = new AllowanceFlowProvider('openrouter:paid-model', insightPayload('paid'));
    $free = new AllowanceFlowProvider('openrouter:free-model', insightPayload('free'));

    $this->app->bind(AiProvider::class, fn () => new PlanAwareAiProvider(
        [$free],
        [$paid],
        app(AiTierResolver::class),
        app(AiAllowanceContext::class),
    ));

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/insights/generate", ['type' => 'weekly'])
        ->assertCreated()
        ->assertJsonPath('insight.ai_model', 'openrouter:free-model');

    expect($paid->calls)->toBe(0)
        ->and($free->calls)->toBe(1)
        ->and(AiInsight::withoutGlobalScope('organization')->where('site_id', $site->id)->count())->toBe(1)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(0)
        ->and(AiAllowanceReservation::withoutGlobalScope('organization')->count())->toBe(0);
});

it('charges the tariff once for a paid insight that the paid model answers', function () {
    [$user, $org] = paidAllowanceFlowOrg('allowance-flow-paid@example.com', ['monthly_ai_credits' => 1000]);
    $site = allowanceFlowSite($org);

    $paid = new AllowanceFlowProvider('openrouter:paid-model', insightPayload('paid'));
    $free = new AllowanceFlowProvider('openrouter:free-model', insightPayload('free'));

    $this->app->bind(AiProvider::class, fn () => new PlanAwareAiProvider(
        [$free],
        [$paid],
        app(AiTierResolver::class),
        app(AiAllowanceContext::class),
    ));

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/insights/generate", ['type' => 'weekly'])
        ->assertCreated()
        ->assertJsonPath('insight.ai_model', 'openrouter:paid-model');

    $charges = CreditLedgerEntry::withoutGlobalScope('organization')
        ->where('organization_id', $org->id)
        ->where('reason', 'ai_basic_wordpress_dashboard_insight')
        ->get();

    expect($paid->calls)->toBe(1)
        ->and($free->calls)->toBe(0)
        ->and($charges)->toHaveCount(1)
        ->and($charges->first()->amount_signed)->toBe(-100)
        ->and(AiAllowanceReservation::withoutGlobalScope('organization')->first()->status)
        ->toBe(AiAllowanceReservation::STATUS_COMMITTED);
});

it('allows the Tier 5 Executive insight without unlocking other advanced report types', function () {
    [$user, $org] = makeOrgUser('tier5-executive@example.com');
    $this->seed(\Database\Seeders\PlanSeeder::class);
    $plan = Plan::where('slug', 'appsumo_tier_5')->firstOrFail();
    $org->forceFill(['plan_id' => $plan->id, 'billing_provider' => 'license'])->save();
    $site = allowanceFlowSite($org);
    PerformanceRun::create([
        'site_id' => $site->id,
        'url' => 'https://' . $site->domain,
        'strategy' => 'mobile',
        'status' => 'complete',
        'run_at' => now(),
        'performance_score' => 98,
    ]);
    $this->app->instance(AiProvider::class, new AllowanceFlowProvider('test-model', [
        'headline' => 'Measured performance',
        'summary' => 'Mobile scored 98.',
    ]));

    $this->actingAs($user)->postJson("/api/sites/{$site->id}/insights/generate", ['type' => 'executive'])
        ->assertCreated()
        ->assertJsonPath('insight.source_data_json.evidence.0.source', 'pagespeed');
    $this->postJson("/api/sites/{$site->id}/insights/generate", ['type' => 'report'])
        ->assertForbidden()
        ->assertJsonPath('code', 'feature_not_in_plan');
});
