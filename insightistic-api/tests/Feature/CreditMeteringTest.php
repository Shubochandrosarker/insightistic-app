<?php

use App\Domain\Billing\CreditLedger;
use App\Jobs\RunAiVisibilityJob;
use App\Models\AiVisibilityPrompt;
use App\Models\AiVisibilityRun;
use App\Models\CreditLedgerEntry;
use App\Models\Site;
use App\Services\AiVisibility\PromptRunner;

function makeRun($site, $prompt): AiVisibilityRun
{
    return AiVisibilityRun::create([
        'site_id' => $site->id,
        'prompt_id' => $prompt->id,
        'provider' => 'openai',
        'model' => 'gpt-test',
        'status' => AiVisibilityRun::STATUS_SUCCESS,
        'brand_mentioned' => false,
        'checked_at' => now()->subDay(),
        // Quota counts created_at >= startOfMonth; backdating past midnight
        // on the 1st would land the run in the previous month and the
        // quota/credit tests would only fail on the first day of a month.
        'created_at' => now()->subDay()->max(now()->startOfMonth()),
    ]);
}

/**
 * Insight Credits metering (spec §27: "Keep Insight Credits for expensive
 * variable-cost work"): when the plan's monthly prompt-run quota is
 * exhausted, runs continue on a 1-credit-per-run metered fallback — and
 * stop cleanly when the balance runs dry. Never truly unlimited.
 */
it('runs AI visibility over quota by consuming one credit per run', function () {
    [$user, $org] = makeOrgUser('credits-over@example.com', ['ai_prompt_runs_monthly' => 1]);
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'C', 'domain' => 'c.example.com',
        'normalized_domain' => 'c.example.com', 'platform' => 'generic',
    ]);
    $prompt = AiVisibilityPrompt::create([
        'site_id' => $site->id, 'prompt' => 'best seo tool', 'active' => true,
    ]);

    // Quota already exhausted by an earlier run this month
    makeRun($site, $prompt);

    app(CreditLedger::class)->grant($org, 5, 'test_grant', 'test', 'grant-1');

    // PromptRunner stub: returns a fresh run row and advances last_run_at
    $runner = $this->mock(PromptRunner::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function () use ($prompt, $site) {
        $prompt->update(['last_run_at' => now()]);

        return AiVisibilityRun::create([
            'site_id' => $site->id, 'prompt_id' => $prompt->id,
            'provider' => 'openai', 'model' => 'gpt-test',
            'status' => AiVisibilityRun::STATUS_SUCCESS, 'brand_mentioned' => true,
            'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    app()->instance(PromptRunner::class, $runner);

    (new RunAiVisibilityJob($site->id))->handle($runner);

    expect(CreditLedgerEntry::where('reason', 'ai_prompt_run_over_quota')->count())->toBe(1)
        ->and(app(CreditLedger::class)->balance($org))->toBe(4);
});

it('stops over-quota AI runs when credits run out', function () {
    [$user, $org] = makeOrgUser('credits-dry@example.com', ['ai_prompt_runs_monthly' => 1]);
    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id, 'name' => 'D', 'domain' => 'd.example.com',
        'normalized_domain' => 'd.example.com', 'platform' => 'generic',
    ]);
    $prompt = AiVisibilityPrompt::create([
        'site_id' => $site->id, 'prompt' => 'best tool', 'active' => true,
    ]);
    makeRun($site, $prompt);
    // NO credit grant — balance 0

    $runner = $this->mock(PromptRunner::class);
    $runner->shouldReceive('run')->never();
    app()->instance(PromptRunner::class, $runner);

    (new RunAiVisibilityJob($site->id))->handle($runner);

    expect(AiVisibilityRun::where('site_id', $site->id)->count())->toBe(1); // only the pre-existing run
});
