<?php

use App\Jobs\RunAiVisibilityJob;
use App\Models\AiVisibilityCitation;
use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityMention;
use App\Models\AiVisibilityPrompt;
use App\Models\AiVisibilityRun;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * AI Visibility API (Phase 11, doc 14): tenant isolation, role gates
 * (reads open to anyone with site access; writes owner/admin), the prompt
 * lifecycle (create / soft-delete / cap), competitor CRUD, the check
 * guards (quota + same-day cadence) and the overview payload shape —
 * including metric_definitions, the doc-14 explainability contract.
 *
 * Queue is faked everywhere: this file pins the HTTP surface, the engine
 * itself is covered by AiVisibilityEngineTest.
 */

/** @return array{0: \App\Models\User, 1: \App\Models\Organization, 2: Site} */
function aivApiSite(string $email, ?array $limits = ['ai_prompt_runs_monthly' => 20]): array
{
    [$user, $org] = makeOrgUser($email, $limits);

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => 'Vis Shop '.md5($email),
        'domain'            => 'vis-'.md5($email).'.example.com',
        'connection_status' => 'connected',
        'timezone'          => 'UTC',
    ]);

    return [$user, $org, $site];
}

function aivApiPrompt(Site $site, string $text = 'best wordpress booking plugin', array $overrides = []): AiVisibilityPrompt
{
    return AiVisibilityPrompt::create(array_replace([
        'site_id' => $site->id,
        'prompt'  => $text,
        'active'  => true,
    ], $overrides));
}

function aivApiRun(Site $site, AiVisibilityPrompt $prompt, array $overrides = []): AiVisibilityRun
{
    return AiVisibilityRun::create(array_replace([
        'site_id'         => $site->id,
        'prompt_id'       => $prompt->id,
        'provider'        => 'openai',
        'model'           => 'gpt-test',
        'status'          => AiVisibilityRun::STATUS_SUCCESS,
        'brand_mentioned' => false,
        'checked_at'      => now()->subDay(),
        'created_at'      => now()->subDay(),
    ], $overrides));
}

it('hides every ai-visibility surface from another organization', function () {
    [$userA] = aivApiSite('aiv-api-a@example.com');
    [, , $siteB] = aivApiSite('aiv-api-b@example.com');
    $promptB = aivApiPrompt($siteB);

    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/ai-visibility")->assertNotFound();
    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/ai-visibility/prompts")->assertNotFound();
    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/ai-visibility/mentions")->assertNotFound();
    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/ai-visibility/competitors")->assertNotFound();
    $this->actingAs($userA)
        ->postJson("/api/sites/{$siteB->id}/ai-visibility/prompts", ['prompt' => 'sneaky prompt'])
        ->assertNotFound();
    $this->actingAs($userA)
        ->deleteJson("/api/sites/{$siteB->id}/ai-visibility/prompts/{$promptB->id}")
        ->assertNotFound();
});

it('opens reads to analysts but blocks every write and dispatch', function () {
    Queue::fake();

    [$owner, $org, $site] = aivApiSite('aiv-api-owner@example.com');
    aivApiPrompt($site);

    $analyst = User::create([
        'name' => 'Analyst', 'email' => 'aiv-api-analyst@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($analyst->id, ['role' => 'analyst']);

    // Reads are open to anyone with site access.
    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/ai-visibility")->assertOk();
    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/ai-visibility/prompts")->assertOk();
    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/ai-visibility/mentions")->assertOk();
    $this->actingAs($analyst)->getJson("/api/sites/{$site->id}/ai-visibility/competitors")->assertOk();

    // Writes burn plan quota / provider spend — owner/admin only.
    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/ai-visibility/prompts", ['prompt' => 'new prompt'])
        ->assertForbidden();
    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/ai-visibility/prompts/1/check")
        ->assertForbidden();
    $this->actingAs($analyst)
        ->postJson("/api/sites/{$site->id}/ai-visibility/competitors", ['name' => 'Rival'])
        ->assertForbidden();
    $this->actingAs($analyst)
        ->deleteJson("/api/sites/{$site->id}/ai-visibility/competitors/1")
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('creates, lists and soft-deletes prompts', function () {
    [$owner, , $site] = aivApiSite('aiv-api-prompt@example.com');

    $response = $this->actingAs($owner)->postJson("/api/sites/{$site->id}/ai-visibility/prompts", [
        'prompt'   => '  best woocommerce analytics plugin  ',
        'category' => 'best-crm',
    ]);

    $response->assertStatus(201)->assertJsonPath('prompt.prompt', 'best woocommerce analytics plugin');

    $promptId = $response->json('prompt.id');

    $this->actingAs($owner)->getJson("/api/sites/{$site->id}/ai-visibility/prompts")
        ->assertOk()
        ->assertJsonCount(1, 'prompts')
        ->assertJsonPath('prompts.0.id', $promptId)
        ->assertJsonPath('prompts.0.active', true)
        ->assertJsonPath('quota.key', 'ai_prompt_runs_monthly');

    // Oversized prompts are rejected at the column bound (500).
    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/ai-visibility/prompts", ['prompt' => str_repeat('x', 501)])
        ->assertStatus(422);

    // Soft delete: active=false, history kept.
    $this->actingAs($owner)->deleteJson("/api/sites/{$site->id}/ai-visibility/prompts/{$promptId}")
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(AiVisibilityPrompt::find($promptId)->active)->toBeFalse()
        ->and(AiVisibilityPrompt::find($promptId))->not->toBeNull(); // row survives

    $this->actingAs($owner)->getJson("/api/sites/{$site->id}/ai-visibility/prompts")
        ->assertOk()
        ->assertJsonCount(0, 'prompts');

    // Deleting again is idempotent (soft-delete on the still-existing row),
    // same contract as the rank tracker's keyword removal.
    $this->actingAs($owner)->deleteJson("/api/sites/{$site->id}/ai-visibility/prompts/{$promptId}")
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('caps active prompts per site', function () {
    [$owner, , $site] = aivApiSite('aiv-api-cap@example.com');

    for ($i = 0; $i < 50; $i++) {
        AiVisibilityPrompt::create(['site_id' => $site->id, 'prompt' => "prompt {$i}"]);
    }

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/ai-visibility/prompts", ['prompt' => 'one too many'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ai_prompt_limit_reached')
        ->assertJsonPath('limit.max', 50)
        ->assertJsonPath('limit.used', 50);
});

it('manages competitors with unique names and alias normalization', function () {
    [$owner, , $site] = aivApiSite('aiv-api-comp@example.com');

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/ai-visibility/competitors", [
            'name'    => 'Rival CRM',
            'aliases' => ['RivalCorp', ' ', 'rivalcrm.io'],
        ])
        ->assertStatus(201)
        ->assertJsonPath('competitor.name', 'Rival CRM')
        ->assertJsonPath('competitor.aliases', ['RivalCorp', 'rivalcrm.io']);

    $competitorId = AiVisibilityCompetitor::where('site_id', $site->id)->first()->id;

    // Same name twice is refused before the DB unique constraint.
    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/ai-visibility/competitors", ['name' => 'Rival CRM'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'competitor_exists');

    $this->actingAs($owner)->getJson("/api/sites/{$site->id}/ai-visibility/competitors")
        ->assertOk()
        ->assertJsonCount(1, 'competitors');

    $this->actingAs($owner)->deleteJson("/api/sites/{$site->id}/ai-visibility/competitors/{$competitorId}")
        ->assertOk()
        ->assertJsonPath('ok', true);

    $this->actingAs($owner)->getJson("/api/sites/{$site->id}/ai-visibility/competitors")
        ->assertOk()
        ->assertJsonCount(0, 'competitors');

    $this->actingAs($owner)->deleteJson("/api/sites/{$site->id}/ai-visibility/competitors/{$competitorId}")
        ->assertNotFound();
});

it('guards the single-prompt check with the quota envelope', function () {
    Queue::fake();

    // limit 2, both already spent this month (one of them failed — failed
    // runs count: a failed provider call is still a paid call).
    [$owner, , $site] = aivApiSite('aiv-api-quota@example.com', ['ai_prompt_runs_monthly' => 2]);
    $prompt = aivApiPrompt($site);

    aivApiRun($site, $prompt, ['status' => AiVisibilityRun::STATUS_SUCCESS]);
    aivApiRun($site, $prompt, ['status' => AiVisibilityRun::STATUS_FAILED]);

    $this->actingAs($owner)->postJson("/api/sites/{$site->id}/ai-visibility/prompts/{$prompt->id}/check")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ai_visibility_quota_reached')
        ->assertJsonPath('limit.key', 'ai_prompt_runs_monthly')
        ->assertJsonPath('limit.max', 2)
        ->assertJsonPath('limit.used', 2);

    // A plan without allowance is blocked the same way.
    [$freeOwner, , $freeSite] = aivApiSite('aiv-api-free@example.com', ['ai_prompt_runs_monthly' => 0]);
    $freePrompt = aivApiPrompt($freeSite);

    $this->actingAs($freeOwner)
        ->postJson("/api/sites/{$freeSite->id}/ai-visibility/prompts/{$freePrompt->id}/check")
        ->assertStatus(422)
        ->assertJsonPath('code', 'ai_visibility_quota_reached')
        ->assertJsonPath('limit.max', 0);

    Queue::assertNothingPushed();
});

it('dispatches a single-prompt check and enforces the same-day cadence', function () {
    Queue::fake();

    [$owner, , $site] = aivApiSite('aiv-api-check@example.com');
    $prompt = aivApiPrompt($site);

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/ai-visibility/prompts/{$prompt->id}/check")
        ->assertOk()
        ->assertJsonPath('dispatched', true);

    Queue::assertPushed(RunAiVisibilityJob::class, fn (RunAiVisibilityJob $job) => $job->siteId === $site->id
        && $job->promptId === $prompt->id);

    // A GET on the check route dispatches too (UI-friendly alias).
    $this->actingAs($owner)
        ->getJson("/api/sites/{$site->id}/ai-visibility/prompts/{$prompt->id}/check")
        ->assertOk()
        ->assertJsonPath('dispatched', true);

    // A check inside the rolling 24-hour window is refused BEFORE dispatch
    // (house rank-tracker guard). 2h-old seed: suppressed at any wall clock
    // time — calendar-day seeds were flaky near UTC midnight.
    $checked = aivApiPrompt($site, 'checked already', ['last_run_at' => now()->subHours(2)]);

    $this->actingAs($owner)
        ->postJson("/api/sites/{$site->id}/ai-visibility/prompts/{$checked->id}/check")
        ->assertStatus(422)
        ->assertJsonPath('code', 'already_checked_today');
});

it('returns the overview with metrics, prompts, competitors and metric definitions', function () {
    [$owner, , $site] = aivApiSite('aiv-api-overview@example.com');
    aivApiPrompt($site, 'how to improve wordpress core web vitals');

    $run = aivApiRun($site, AiVisibilityPrompt::where('site_id', $site->id)->first(), [
        'brand_mentioned' => true,
        'provider'        => 'openai',
    ]);
    AiVisibilityMention::create([
        'run_id' => $run->id, 'site_id' => $site->id, 'entity_name' => $site->name,
        'is_owned' => true, 'is_competitor' => false, 'position_order' => 1,
        'sentiment' => 'positive', 'created_at' => now(),
    ]);

    $response = $this->actingAs($owner)->getJson("/api/sites/{$site->id}/ai-visibility")
        ->assertOk();

    $response->assertJsonStructure([
        'metrics' => [
            'days', 'prompts_tracked', 'runs_total', 'runs_failed',
            'mention_rate', 'citation_rate', 'avg_owned_citations_per_run',
            'competitor_share', 'by_provider', 'trend', 'metric_definitions',
        ],
        'prompts',
        'competitors',
        'quota' => ['key', 'used', 'limit'],
    ]);

    $payload = $response->json();

    // Whole-number floats round-trip through JSON as ints — compare loosely.
    expect($payload['metrics']['runs_total'])->toBe(1)
        ->and($payload['metrics']['mention_rate'])->toEqual(100.0)
        ->and($payload['metrics']['metric_definitions'])->toHaveKey('competitor_share')
        ->and($payload['prompts'])->toHaveCount(1)
        ->and($payload['prompts'][0]['latest_run']['brand_mentioned'])->toBeTrue()
        ->and($payload['prompts'][0]['latest_run']['provider'])->toBe('openai')
        ->and($payload['quota'])->toBe(['key' => 'ai_prompt_runs_monthly', 'used' => 1, 'limit' => 20]);
});

it('lists mentions with run context and filters by run_id', function () {
    [$owner, , $site] = aivApiSite('aiv-api-mentions@example.com');

    $p1 = aivApiPrompt($site, 'prompt one');
    $p2 = aivApiPrompt($site, 'prompt two');

    $r1 = aivApiRun($site, $p1, ['brand_mentioned' => true]);
    $r2 = aivApiRun($site, $p2, ['brand_mentioned' => false]);

    AiVisibilityMention::create(['run_id' => $r1->id, 'site_id' => $site->id, 'entity_name' => $site->name, 'is_owned' => true, 'is_competitor' => false, 'position_order' => 1, 'sentiment' => 'neutral', 'created_at' => now()->subHour()]);
    AiVisibilityMention::create(['run_id' => $r2->id, 'site_id' => $site->id, 'entity_name' => 'Rival', 'is_owned' => false, 'is_competitor' => true, 'position_order' => 1, 'sentiment' => 'negative', 'created_at' => now()]);

    $all = $this->actingAs($owner)->getJson("/api/sites/{$site->id}/ai-visibility/mentions")
        ->assertOk()
        ->assertJsonCount(2, 'mentions')
        ->json('mentions');

    // Newest first (r2's mention was created later).
    expect($all[0]['entity_name'])->toBe('Rival')
        ->and($all[0]['is_competitor'])->toBeTrue()
        ->and($all[0]['run']['prompt'])->toBe('prompt two')
        ->and($all[1]['run']['brand_mentioned'])->toBeTrue();

    $scoped = $this->actingAs($owner)
        ->getJson("/api/sites/{$site->id}/ai-visibility/mentions?run_id={$r1->id}")
        ->assertOk()
        ->assertJsonCount(1, 'mentions')
        ->json('mentions');

    expect($scoped[0]['entity_name'])->toBe($site->name)
        ->and($scoped[0]['is_owned'])->toBeTrue();

    // No OWNED citation among the eligible runs → citation metrics stay 0.
    AiVisibilityCitation::create(['run_id' => $r1->id, 'site_id' => $site->id, 'url' => 'https://example.com/a', 'domain' => 'example.com', 'is_owned' => false, 'created_at' => now()]);

    $overview = $this->actingAs($owner)->getJson("/api/sites/{$site->id}/ai-visibility")->json('metrics');

    expect($overview['citation_rate'])->toEqual(0.0)
        ->and($overview['avg_owned_citations_per_run'])->toEqual(0.0);
});
