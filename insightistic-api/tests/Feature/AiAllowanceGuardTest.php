<?php

use App\Domain\Billing\AiAllowanceContext;
use App\Domain\Billing\AiAllowanceGuard;
use App\Domain\Billing\CreditWallet;
use App\Models\AiAllowanceReservation;
use App\Models\CreditLedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTierResolver;
use App\Services\Ai\PlanAwareAiProvider;
use App\Services\Ai\RuleProvider;
use Illuminate\Support\Str;

/**
 * AI allowance enforcement (pricing architecture §6: "Reserve the action cost
 * before the AI call, commit on success, release on failure or timeout. Idempotency
 * key is the ai_runs id") plus the approved §5 rule that a short allowance
 * downgrades to the free-tier models instead of hard-failing.
 */
class AllowanceSpyProvider implements AiProvider
{
    public int $calls = 0;

    public function __construct(
        private string $label,
        private ?array $result,
        private bool $throw = false,
    ) {}

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        $this->calls++;

        if ($this->throw) {
            throw new RuntimeException('provider timed out');
        }

        return $this->result;
    }

    public function model(): string
    {
        return $this->label;
    }
}

/** Paid tier (priced plan + active subscription) so AiTierResolver routes to the paid chain. */
function paidAllowanceOrg(string $email, ?array $limits = null): array
{
    [$user, $org] = makeOrgUser($email, $limits);

    $plan = Plan::create([
        'name' => 'Growth',
        'slug' => 'growth-' . Str::random(8),
        'price_monthly' => 6900,
        'site_limit' => 10,
        'user_limit' => 3,
        'ai_insight_limit' => 100,
        'report_limit' => 20,
        'limits_json' => $limits,
        'is_active' => true,
    ]);

    Subscription::create([
        'organization_id' => $org->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    return [$user, $org];
}

function ledgerEntriesFor(int $organizationId)
{
    return CreditLedgerEntry::withoutGlobalScope('organization')
        ->where('organization_id', $organizationId)
        ->orderBy('id')
        ->get();
}

it('reserves and commits a paid AI action, charging its tariff exactly once', function () {
    [, $org] = makeOrgUser('allowance-happy@example.com', ['monthly_ai_credits' => 1000]);

    $guard = app(AiAllowanceGuard::class);
    $wallet = app(CreditWallet::class);

    $decision = $guard->reserve($org->id, 'basic_wordpress_dashboard_insight', 4242);

    expect($decision['allowed'])->toBeTrue()
        ->and($decision['reason'])->toBe('reserved')
        ->and($decision['cost'])->toBe(100)
        ->and($decision['reservation'])->toBeInstanceOf(AiAllowanceReservation::class)
        ->and($decision['reservation']->status)->toBe(AiAllowanceReservation::STATUS_RESERVED)
        // A hold is not a spend: the wallet is untouched until commit.
        ->and($wallet->summary($org)['total_usable'])->toBe(1000)
        ->and(ledgerEntriesFor($org->id))->toHaveCount(1); // the monthly grant only

    $committed = $guard->commit($decision['reservation']);

    expect($committed->status)->toBe(AiAllowanceReservation::STATUS_COMMITTED)
        ->and($committed->committed_at)->not->toBeNull()
        ->and($wallet->summary($org)['total_usable'])->toBe(900);

    $charges = CreditLedgerEntry::withoutGlobalScope('organization')
        ->where('organization_id', $org->id)
        ->where('reason', 'ai_basic_wordpress_dashboard_insight')
        ->get();

    expect($charges)->toHaveCount(1)
        ->and($charges->first()->amount_signed)->toBe(-100);
});

it('releases the hold without charging when the paid provider throws', function () {
    [, $org] = paidAllowanceOrg('allowance-throws@example.com', ['monthly_ai_credits' => 1000]);

    $guard = app(AiAllowanceGuard::class);
    $context = new AiAllowanceContext($guard);

    $paid = new AllowanceSpyProvider('openrouter:paid-model', ['title' => 'paid'], throw: true);
    $rule = new RuleProvider;
    $free = new AllowanceSpyProvider('openrouter:free-model', ['title' => 'free']);

    $provider = new PlanAwareAiProvider([$free], [$paid, $rule], app(AiTierResolver::class), $context);
    $context->for($org->id, 'basic_wordpress_dashboard_insight', 'run-throws');

    $result = $provider->generate('system', ['metrics' => ['revenue' => 10]], $org->id);

    expect($paid->calls)->toBe(1)
        ->and($result)->not->toBeNull()
        // The $0 rule fallback answered, so the action was never billed.
        ->and($provider->model())->toBe('rule-based-v1')
        ->and(ledgerEntriesFor($org->id))->toHaveCount(1) // monthly grant only, no deduction
        ->and(AiAllowanceReservation::withoutGlobalScope('organization')->where('run_reference', 'run-throws')->first()->status)
        ->toBe(AiAllowanceReservation::STATUS_RELEASED);
});

it('charges exactly once when the same ai run is committed twice', function () {
    [, $org] = makeOrgUser('allowance-idempotent@example.com', ['monthly_ai_credits' => 1000]);

    $guard = app(AiAllowanceGuard::class);

    $first = $guard->reserve($org->id, 'seo_opportunity_analysis', 777);
    $guard->commit($first['reservation']);

    // A retried run reuses the same id: it must settle, not bill again.
    $second = $guard->reserve($org->id, 'seo_opportunity_analysis', 777);
    $guard->commit($second['reservation']);

    $charges = CreditLedgerEntry::withoutGlobalScope('organization')
        ->where('organization_id', $org->id)
        ->where('reason', 'ai_seo_opportunity_analysis')
        ->get();

    expect($second['reason'])->toBe('existing_reservation')
        ->and($second['reservation']->id)->toBe($first['reservation']->id)
        ->and($charges)->toHaveCount(1)
        ->and(abs((int) $charges->sum('amount_signed')))->toBe(200)
        ->and(app(CreditWallet::class)->summary($org)['total_usable'])->toBe(800);
});

it('skips paid providers and charges nothing when the allowance is short', function () {
    [$user, $org] = paidAllowanceOrg('allowance-short@example.com'); // no AI credits on the plan

    $guard = app(AiAllowanceGuard::class);
    $context = new AiAllowanceContext($guard);

    $paid = new AllowanceSpyProvider('openrouter:paid-model', ['title' => 'paid']);
    $free = new AllowanceSpyProvider('openrouter:free-model', ['title' => 'free answer']);

    $provider = new PlanAwareAiProvider([$free], [$paid], app(AiTierResolver::class), $context);
    $context->for($org->id, 'comprehensive_ai_business_audit', 'run-short');

    $result = $provider->generate('system', ['metrics' => []]);

    expect($result)->toBe(['title' => 'free answer'])
        // Provider choice: the paid model was never called at all.
        ->and($paid->calls)->toBe(0)
        ->and($free->calls)->toBe(1)
        ->and($provider->model())->toBe('openrouter:free-model')
        ->and(ledgerEntriesFor($org->id))->toHaveCount(0)
        ->and(AiAllowanceReservation::withoutGlobalScope('organization')->count())->toBe(0);
});

it('counts open holds against the allowance so two actions cannot overdraw one balance', function () {
    [, $org] = makeOrgUser('allowance-holds@example.com', ['monthly_ai_credits' => 150]);

    $guard = app(AiAllowanceGuard::class);

    $first = $guard->reserve($org->id, 'basic_wordpress_dashboard_insight', 'run-hold-a');
    $second = $guard->reserve($org->id, 'basic_wordpress_dashboard_insight', 'run-hold-b');

    expect($first['allowed'])->toBeTrue()
        ->and($second['allowed'])->toBeFalse()
        ->and($second['reason'])->toBe('insufficient_credits')
        ->and($second['available'])->toBe(50);

    // Releasing the first hold returns the headroom to the next action.
    $guard->release($first['reservation']);

    expect($guard->reserve($org->id, 'basic_wordpress_dashboard_insight', 'run-hold-c')['allowed'])->toBeTrue();
});

it('never charges a released hold, even when a late commit arrives', function () {
    [, $org] = makeOrgUser('allowance-late-commit@example.com', ['monthly_ai_credits' => 1000]);

    $guard = app(AiAllowanceGuard::class);
    $decision = $guard->reserve($org->id, 'woocommerce_business_analysis', 'run-late');

    $guard->release($decision['reservation']);
    $guard->commit($decision['reservation']); // timeout raced a slow success
    $guard->release($decision['reservation']);

    expect($decision['reservation']->fresh()->status)->toBe(AiAllowanceReservation::STATUS_RELEASED)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->where('reason', 'ai_woocommerce_business_analysis')->count())->toBe(0)
        ->and(app(CreditWallet::class)->summary($org)['total_usable'])->toBe(1000);
});

it('does not reopen a released hold when the balance can no longer cover it', function () {
    [, $org] = makeOrgUser('allowance-retry-short@example.com', ['monthly_ai_credits' => 100]);

    $guard = app(AiAllowanceGuard::class);
    $first = $guard->reserve($org->id, 'basic_wordpress_dashboard_insight', 'run-retry');
    $guard->release($first['reservation']);

    // The allowance is spent elsewhere before the retry arrives.
    app(CreditWallet::class)->consume($org, 100, 'manual_test_spend', 'manual-spend-1');

    $retry = $guard->reserve($org->id, 'basic_wordpress_dashboard_insight', 'run-retry');

    expect($retry['allowed'])->toBeFalse()
        ->and($retry['reason'])->toBe('insufficient_credits')
        ->and($first['reservation']->fresh()->status)->toBe(AiAllowanceReservation::STATUS_RELEASED);
});

it('keeps the ledger append-only with a correct balance_after chain', function () {
    [, $org] = makeOrgUser('allowance-append-only@example.com', ['monthly_ai_credits' => 1000]);

    $guard = app(AiAllowanceGuard::class);
    $wallet = app(CreditWallet::class);
    $wallet->ensureCurrentPeriod($org); // the +1000 monthly grant

    $guard->commit($guard->reserve($org->id, 'basic_wordpress_dashboard_insight', 'run-1')['reservation']);
    $guard->release($guard->reserve($org->id, 'basic_wordpress_dashboard_insight', 'run-2')['reservation']);
    $guard->commit($guard->reserve($org->id, 'seo_opportunity_analysis', 'run-3')['reservation']);

    $entries = ledgerEntriesFor($org->id);

    $running = 0;
    $previousId = 0;

    foreach ($entries as $entry) {
        $running += $entry->amount_signed;

        expect($entry->id)->toBeGreaterThan($previousId)
            ->and($entry->balance_after)->toBe($running);

        $previousId = $entry->id;
    }

    expect($entries)->toHaveCount(3) // grant, -100, -200 — the release wrote nothing
        ->and($running)->toBe(700)
        ->and($wallet->summary($org)['total_usable'])->toBe(700);
});
