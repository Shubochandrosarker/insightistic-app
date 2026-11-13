<?php

use App\Jobs\RankCheckJob;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Models\Subscription;
use App\Services\Rank\RankProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Scheduled rank checks (Phase 10, doc 13) + per-plan cadence: daily tiers
 * dispatch every night, weekly tiers (free and every lifetime plan — the LTD
 * price only works at one check per keyword per week) dispatch on their
 * crc32(org id) % 7 slot day only, and --force bypasses cadence for manual
 * runs.
 */

/** Provider stub: the command only asks whether rank tracking is configured. */
class CadenceRankProvider implements RankProvider
{
    public function checkBatch(array $keywords): array
    {
        return [];
    }

    public function available(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'dataforseo';
    }
}

function rankCadencePlan(string $slug): Plan
{
    return Plan::firstOrCreate(['slug' => $slug], [
        'name' => ucfirst(str_replace('_', ' ', $slug)),
        'site_limit' => 5,
        'user_limit' => 5,
        'ai_insight_limit' => 10,
        'report_limit' => 10,
        'is_active' => true,
    ]);
}

/** Organization whose resolved entitlement plan is the catalogued $slug. */
function rankCadenceOrg(string $slug, string $email): Organization
{
    [, $org] = makeOrgUser($email);
    $plan = rankCadencePlan($slug);

    Subscription::create([
        'organization_id' => $org->id,
        'plan_id'         => $plan->id,
        'status'          => 'active',
    ]);

    $org->forceFill(['plan_id' => $plan->id])->save();

    return $org;
}

function rankCadenceSite(Organization $org, string $domain): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => $domain,
        'domain'            => $domain,
        'connection_status' => 'connected',
        'timezone'          => 'UTC',
    ]);
}

function rankCadenceKeyword(Site $site, string $keyword): RankKeyword
{
    return RankKeyword::create([
        'site_id'     => $site->id,
        'keyword'     => $keyword,
        'engine'      => 'google',
        'device'      => 'desktop',
        'active'      => true,
        'config_hash' => RankKeyword::makeConfigHash($keyword, 'google', null, 'en', 'desktop', null),
    ]);
}

/** Monday 06:00 UTC anchor; addDays($slot) lands on weekday slot (Mon = 0). */
function rankCadenceMonday(): Carbon
{
    return Carbon::parse('2026-03-02 06:00:00');
}

beforeEach(function () {
    Queue::fake();
    $this->app->instance(RankProvider::class, new CadenceRankProvider());
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches a daily plan on every weekday', function () {
    $org = rankCadenceOrg('growth', 'rank-cadence-daily@example.com');
    $site = rankCadenceSite($org, 'daily-cadence.example');
    rankCadenceKeyword($site, 'daily keyword');

    Carbon::setTestNow(rankCadenceMonday()); // Monday
    $this->artisan('insightistic:rank-check')->assertSuccessful();
    Queue::assertPushed(RankCheckJob::class, 1);

    Carbon::setTestNow(rankCadenceMonday()->addDays(2)); // Wednesday
    $this->artisan('insightistic:rank-check')->assertSuccessful();
    Queue::assertPushed(RankCheckJob::class, 2);
});

it('dispatches a weekly plan only on its crc32 slot day', function () {
    $org = rankCadenceOrg('angel_solo', 'rank-cadence-weekly@example.com');
    $site = rankCadenceSite($org, 'weekly-cadence.example');
    rankCadenceKeyword($site, 'weekly keyword');

    $slot = crc32((string) $org->id) % 7;
    $otherDay = ($slot + 1) % 7;

    Carbon::setTestNow(rankCadenceMonday()->addDays($otherDay));
    $this->artisan('insightistic:rank-check')
        ->expectsOutputToContain('0 dispatched, 1 cadence-skipped, 0 skipped')
        ->assertSuccessful();
    Queue::assertNothingPushed();

    Carbon::setTestNow(rankCadenceMonday()->addDays($slot));
    $this->artisan('insightistic:rank-check')
        ->expectsOutputToContain('1 dispatched, 0 cadence-skipped, 0 skipped')
        ->assertSuccessful();
    Queue::assertPushed(RankCheckJob::class, 1);
});

it('bypasses cadence with --force', function () {
    $org = rankCadenceOrg('appsumo_tier_2', 'rank-cadence-force@example.com');
    $site = rankCadenceSite($org, 'force-cadence.example');
    rankCadenceKeyword($site, 'forced keyword');

    $slot = crc32((string) $org->id) % 7;

    Carbon::setTestNow(rankCadenceMonday()->addDays(($slot + 1) % 7));
    $this->artisan('insightistic:rank-check')->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('insightistic:rank-check', ['--force' => true])
        ->expectsOutputToContain('1 dispatched, 0 cadence-skipped, 0 skipped')
        ->assertSuccessful();
    Queue::assertPushed(RankCheckJob::class, 1);
});
