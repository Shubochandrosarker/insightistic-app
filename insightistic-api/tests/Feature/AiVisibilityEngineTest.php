<?php

use App\Jobs\RunAiVisibilityJob;
use App\Models\AiVisibilityCitation;
use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityMention;
use App\Models\AiVisibilityPrompt;
use App\Models\AiVisibilityRun;
use App\Models\Site;
use App\Services\Ai\AiProvider;
use App\Services\AiVisibility\PromptRunner;
use App\Services\AiVisibility\VisibilityMetrics;

/**
 * AI Visibility engine (Phase 11, doc 14): PromptRunner over the faked
 * provider chain, the failure-isolation contract, the metrics math computed
 * from seeded observation rows, and the monthly quota gate.
 *
 * The provider is faked at the AiProvider container binding — the same
 * idiom InsightGeneratorConsentTest uses — so PromptRunner exercises the
 * REAL ProviderResolver path (wpistic gateway → openai → rule fallback)
 * end to end. Nothing here talks to a model vendor.
 */

/**
 * Scripted AiProvider fake: returns/consumes queued responses in order.
 * Each script entry is an assoc payload (the "provider response"), null
 * (provider failure signal) or a Throwable (transport-level explosion).
 */
class FakeScriptedAiProvider implements AiProvider
{
    public array $calls = [];

    /** @var list<array|null|Throwable> */
    private array $script;

    public function __construct(array $script)
    {
        $this->script = array_values($script);
    }

    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        $this->calls[] = ['system' => $system, 'context' => $context, 'identity_id' => $identityId];

        $next = array_shift($this->script);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function model(): string
    {
        return 'fake-visibility-model';
    }

    public function scriptRemaining(): int
    {
        return count($this->script);
    }
}

/** Canned provider payload mentioning the brand, a competitor and two URLs. */
function aivEngineAnswer(): array
{
    return [
        // "great" sits next to the brand; the competitor is kept >80 chars
        // away from every sentiment word so its heuristic is deterministically
        // neutral (see MentionExtractor's documented window).
        'answer'   => 'Acme Shop is great for analytics. '.str_repeat('It ships useful weekly reports. ', 4)
            .'Rival CRM appears in some comparisons. Sources: '
            .'https://acme.example.com/blog/post and https://rivalcrm.io/docs',
        'usage'    => ['total_tokens' => 4321],
        'cost_usd' => 0.4321,
    ];
}

/** @return array{0: \App\Models\User, 1: \App\Models\Organization, 2: Site} */
function aivEngineSite(string $email, ?array $limits = ['ai_prompt_runs_monthly' => 100]): array
{
    [$user, $org] = makeOrgUser($email, $limits);

    $site = Site::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'name'              => 'Acme Shop',
        'domain'            => 'acme.example.com',
        'connection_status' => 'connected',
        'timezone'          => 'UTC',
    ]);

    return [$user, $org, $site];
}

function aivEnginePrompt(Site $site, string $text = 'best crm for small teams', array $overrides = []): AiVisibilityPrompt
{
    return AiVisibilityPrompt::create(array_replace([
        'site_id' => $site->id,
        'prompt'  => $text,
        'active'  => true,
    ], $overrides));
}

function aivEngineCompetitor(Site $site, string $name = 'Rival CRM', array $aliases = []): AiVisibilityCompetitor
{
    return AiVisibilityCompetitor::create([
        'site_id'      => $site->id,
        'name'         => $name,
        'aliases_json' => $aliases ?: null,
    ]);
}

function aivEngineRun(Site $site, AiVisibilityPrompt $prompt, array $overrides = []): AiVisibilityRun
{
    return AiVisibilityRun::create(array_replace([
        'site_id'         => $site->id,
        'prompt_id'       => $prompt->id,
        'provider'        => 'openai',
        'model'           => 'gpt-test',
        'status'          => AiVisibilityRun::STATUS_SUCCESS,
        'tokens_used'     => 10,
        'cost_units'      => 0.1,
        'brand_mentioned' => false,
        'checked_at'      => now()->subDay(),
        'created_at'      => now()->subDay(),
    ], $overrides));
}

it('runs a prompt through the provider chain and records the full observation', function () {
    [, $org, $site] = aivEngineSite('aiv-engine-run@example.com');
    $prompt = aivEnginePrompt($site);
    aivEngineCompetitor($site);

    $fake = new FakeScriptedAiProvider([aivEngineAnswer()]);
    $this->app->bind(AiProvider::class, fn () => $fake);

    $run = app(PromptRunner::class)->run($prompt->refresh(), $site);

    // The monitored prompt went through the REAL provider contract: verbatim
    // as user context, house system instruction, org identity attached.
    expect($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['context'])->toHaveKey('prompt', 'best crm for small teams')
        ->and($fake->calls[0]['system'])->toContain('typical AI assistant')
        ->and($fake->calls[0]['identity_id'])->toBe($org->id);

    // Run row: provider/model/surface metadata + metered usage (doc 14).
    expect($run->status)->toBe('success')
        ->and($run->provider)->toBe('openai') // fake model has no gateway prefix
        ->and($run->model)->toBe('fake-visibility-model')
        ->and($run->tokens_used)->toBe(4321)
        ->and((float) $run->cost_units)->toEqualWithDelta(0.4321, 0.00001)
        ->and($run->response_hash)->toBe(md5(aivEngineAnswer()['answer']))
        ->and($run->brand_mentioned)->toBeTrue()
        ->and($prompt->refresh()->last_run_at)->not->toBeNull();

    // Mentions: owned brand first (order of appearance), competitor second.
    $mentions = $run->mentions()->orderBy('position_order')->get();

    expect($mentions)->toHaveCount(2)
        ->and($mentions[0]->entity_name)->toBe('Acme Shop')
        ->and($mentions[0]->is_owned)->toBeTrue()
        ->and($mentions[0]->is_competitor)->toBeFalse()
        ->and($mentions[0]->position_order)->toBe(1)
        ->and($mentions[0]->sentiment)->toBe('positive') // "great" nearby
        ->and($mentions[1]->entity_name)->toBe('Rival CRM')
        ->and($mentions[1]->is_owned)->toBeFalse()
        ->and($mentions[1]->is_competitor)->toBeTrue()
        ->and($mentions[1]->position_order)->toBe(2)
        ->and($mentions[1]->sentiment)->toBe('neutral');

    // Citations: owned-domain resolution, www-insensitive.
    $citations = $run->citations()->orderBy('id')->get();

    expect($citations)->toHaveCount(2)
        ->and($citations[0]->domain)->toBe('acme.example.com')
        ->and($citations[0]->is_owned)->toBeTrue()
        ->and($citations[1]->domain)->toBe('rivalcrm.io')
        ->and($citations[1]->is_owned)->toBeFalse();
});

it('honors the allow_external_ai consent decision through the resolver', function () {
    [, $org, $site] = aivEngineSite('aiv-engine-consent@example.com');
    $org->forceFill(['allow_external_ai' => false])->save();

    $prompt = aivEnginePrompt($site);

    // An external provider that must never be reached.
    $fake = new FakeScriptedAiProvider([aivEngineAnswer()]);
    $this->app->bind(AiProvider::class, fn () => $fake);

    $run = app(PromptRunner::class)->run($prompt, $site);

    // The resolver swapped in the $0 rule provider, which cannot answer a
    // market-facing question — recorded honestly as a failed run with the
    // provider that actually ran, never fabricated into a clean answer.
    expect($fake->calls)->toHaveCount(0)
        ->and($run->status)->toBe('failed')
        ->and($run->provider)->toBe('rule')
        ->and($run->model)->toBe('rule-based-v1')
        ->and($run->brand_mentioned)->toBeFalse();
});

it('records a failed run per prompt and keeps the sweep going', function () {
    [, , $site] = aivEngineSite('aiv-engine-fail@example.com');
    $first = aivEnginePrompt($site, 'first prompt');
    $second = aivEnginePrompt($site, 'second prompt');

    $fake = new FakeScriptedAiProvider([
        new RuntimeException('visibility provider exploded'),
        aivEngineAnswer(),
    ]);
    $this->app->bind(AiProvider::class, fn () => $fake);

    (new RunAiVisibilityJob($site->id))->handle(app(PromptRunner::class));

    $runs = AiVisibilityRun::where('site_id', $site->id)->orderBy('id')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->prompt_id)->toBe($first->id)
        ->and($runs[0]->status)->toBe('failed')
        ->and($runs[0]->error)->toContain('exploded')
        ->and($runs[0]->brand_mentioned)->toBeFalse()
        // Failure still advances last_run_at — the run consumed quota, so
        // the daily due-pick must not re-fire it today.
        ->and($first->refresh()->last_run_at)->not->toBeNull()
        // Isolation: the second prompt ran and succeeded anyway.
        ->and($runs[1]->prompt_id)->toBe($second->id)
        ->and($runs[1]->status)->toBe('success')
        ->and($runs[1]->brand_mentioned)->toBeTrue();
});

it('computes metrics only from stored observations', function () {
    [, , $site] = aivEngineSite('aiv-engine-metrics@example.com');
    $prompt = aivEnginePrompt($site);

    // r1: success, brand mentioned, 2 owned + 1 external citation.
    $r1 = aivEngineRun($site, $prompt, ['brand_mentioned' => true]);
    AiVisibilityCitation::create(['run_id' => $r1->id, 'site_id' => $site->id, 'url' => 'https://acme.example.com/a', 'domain' => 'acme.example.com', 'is_owned' => true, 'created_at' => now()]);
    AiVisibilityCitation::create(['run_id' => $r1->id, 'site_id' => $site->id, 'url' => 'https://acme.example.com/b', 'domain' => 'acme.example.com', 'is_owned' => true, 'created_at' => now()]);
    AiVisibilityCitation::create(['run_id' => $r1->id, 'site_id' => $site->id, 'url' => 'https://thirdparty.io/x', 'domain' => 'thirdparty.io', 'is_owned' => false, 'created_at' => now()]);

    // r2: success, brand absent, competitor mentioned (loss of voice).
    $r2 = aivEngineRun($site, $prompt, ['brand_mentioned' => false, 'provider' => 'openai']);
    AiVisibilityMention::create(['run_id' => $r2->id, 'site_id' => $site->id, 'entity_name' => 'Rival', 'is_owned' => false, 'is_competitor' => true, 'position_order' => 1, 'sentiment' => 'neutral', 'created_at' => now()]);

    // r3: success, brand absent, nothing else.
    aivEngineRun($site, $prompt, ['brand_mentioned' => false, 'provider' => 'rule']);

    // r4: failed — observed nothing, so it is excluded from every rate.
    aivEngineRun($site, $prompt, ['status' => AiVisibilityRun::STATUS_FAILED, 'brand_mentioned' => false]);

    // Outside the 30-day window — must not enter any metric.
    aivEngineRun($site, $prompt, ['brand_mentioned' => true, 'checked_at' => now()->subDays(40), 'created_at' => now()->subDays(40)]);

    $metrics = app(VisibilityMetrics::class)->forSite($site, 30);

    // The seeded observations were checked yesterday — the trend bucket
    // follows their stored checked_at, never "today".
    $bucketDay = now()->subDay()->toDateString();

    expect($metrics['days'])->toBe(30)
        ->and($metrics['prompts_tracked'])->toBe(1)
        ->and($metrics['runs_total'])->toBe(4)
        ->and($metrics['runs_failed'])->toBe(1)
        // 1 of 3 eligible runs mentioned the brand.
        ->and($metrics['mention_rate'])->toBe(33.3)
        // 1 of 3 eligible runs cited an owned domain.
        ->and($metrics['citation_rate'])->toBe(33.3)
        // 2 owned citations across 3 eligible runs.
        ->and($metrics['avg_owned_citations_per_run'])->toBe(0.67)
        // 1 of 3 eligible runs: competitor mentioned AND brand absent.
        ->and($metrics['competitor_share'])->toBe(33.3)
        // Provider coverage is reported separately, never blended (doc 14).
        ->and($metrics['by_provider'])->toBe([
            ['provider' => 'openai', 'runs' => 2, 'mention_rate' => 50.0],
            ['provider' => 'rule', 'runs' => 1, 'mention_rate' => 0.0],
        ])
        ->and($metrics['trend'])->toBe([
            ['date' => $bucketDay, 'runs' => 4, 'mentions' => 1],
        ])
        // Doc 14 acceptance: the payload explains its own formulas.
        ->and($metrics['metric_definitions'])->toBe(VisibilityMetrics::definitions())
        ->and($metrics['metric_definitions']['mention_rate'])->toContain('eligible (successful) runs');
});

it('stops at the monthly quota and skips plans without allowance', function () {
    [, , $site] = aivEngineSite('aiv-engine-quota@example.com', ['ai_prompt_runs_monthly' => 2]);
    $p1 = aivEnginePrompt($site, 'prompt one');
    $p2 = aivEnginePrompt($site, 'prompt two');
    $p3 = aivEnginePrompt($site, 'prompt three');

    $fake = new FakeScriptedAiProvider([aivEngineAnswer(), aivEngineAnswer(), aivEngineAnswer()]);
    $this->app->bind(AiProvider::class, fn () => $fake);

    (new RunAiVisibilityJob($site->id))->handle(app(PromptRunner::class));

    // Limit 2, three due prompts: exactly the first two ran, capped.
    expect(AiVisibilityRun::where('site_id', $site->id)->count())->toBe(2)
        ->and($p1->refresh()->last_run_at)->not->toBeNull()
        ->and($p2->refresh()->last_run_at)->not->toBeNull()
        ->and($p3->refresh()->last_run_at)->toBeNull()
        ->and($fake->scriptRemaining())->toBe(1);

    // The month's two runs now fill the quota — a re-run is a no-op that
    // burns no provider calls (failed runs count too, but there are none).
    (new RunAiVisibilityJob($site->id))->handle(app(PromptRunner::class));

    expect(AiVisibilityRun::where('site_id', $site->id)->count())->toBe(2)
        ->and($fake->scriptRemaining())->toBe(1);
});

it('skips archived sites and plans with no allowance without touching the provider', function () {
    [, , $site] = aivEngineSite('aiv-engine-skip@example.com', ['ai_prompt_runs_monthly' => 0]);
    aivEnginePrompt($site, 'never runs');

    $fake = new FakeScriptedAiProvider([aivEngineAnswer()]);
    $this->app->bind(AiProvider::class, fn () => $fake);

    (new RunAiVisibilityJob($site->id))->handle(app(PromptRunner::class));

    expect(AiVisibilityRun::where('site_id', $site->id)->count())->toBe(0)
        ->and($fake->scriptRemaining())->toBe(1);

    // An archived site is skipped the same way.
    $site->forceFill(['archived_at' => now()])->save();
    $site->organization->plan->update(['limits_json' => ['ai_prompt_runs_monthly' => 100]]);

    (new RunAiVisibilityJob($site->id))->handle(app(PromptRunner::class));

    expect(AiVisibilityRun::where('site_id', $site->id)->count())->toBe(0)
        ->and($fake->scriptRemaining())->toBe(1);
});

it('never re-runs a prompt checked within the last 24 hours (daily cadence)', function () {
    [, , $site] = aivEngineSite('aiv-engine-cadence@example.com');
    // 2h old: under the rolling-24h cadence this is suppressed at any wall
    // clock time — calendar-day seeds made the test flaky near UTC midnight.
    aivEnginePrompt($site, 'already done today', ['last_run_at' => now()->subHours(2)]);

    $fake = new FakeScriptedAiProvider([aivEngineAnswer()]);
    $this->app->bind(AiProvider::class, fn () => $fake);

    (new RunAiVisibilityJob($site->id))->handle(app(PromptRunner::class));

    expect(AiVisibilityRun::where('site_id', $site->id)->count())->toBe(0)
        ->and($fake->scriptRemaining())->toBe(1);
});
