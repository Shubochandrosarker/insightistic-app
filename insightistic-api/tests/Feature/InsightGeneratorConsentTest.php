<?php

use App\Models\Site;
use App\Services\Ai\AiProvider;
use App\Services\InsightGenerator;
use App\Support\DateRange;

/**
 * Regression test for the gap flagged in docs/CONVERSATIONAL-ANALYTICS-AUDIT.md
 * §3: CopilotChat already honored an organization's `allow_external_ai`
 * consent flag, but InsightGenerator (legacy generate() + generateContextual(),
 * including the nightly GenerateProactiveInsights command) called the
 * configured AiProvider directly regardless of it — silently sending
 * business data to an external provider a customer had explicitly opted
 * out of. Fixed by InsightGenerator::resolveProvider().
 */
function consentSite(\App\Models\Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Consent Shop',
        'domain' => 'consent-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

/** Throws if ever called — proves the external provider was never reached. */
class ExplodingAiProviderForTest implements AiProvider
{
    public function generate(string $system, array $context, ?int $identityId = null): ?array
    {
        throw new RuntimeException('External AI provider was called despite allow_external_ai=false.');
    }

    public function model(): string
    {
        return 'exploding-test-provider';
    }
}

it('forces the deterministic rule provider when the organization has disabled external AI', function () {
    [, $org] = makeOrgUser('consent-off@example.com');
    $org->forceFill(['allow_external_ai' => false])->save();
    $site = consentSite($org);

    $this->app->bind(AiProvider::class, ExplodingAiProviderForTest::class);

    $insight = app(InsightGenerator::class)->summarize($site, DateRange::fromRequest('last_7_days', null, null, 'UTC'));

    expect($insight)->not->toBeNull()
        ->and($insight['model'])->toBe('rule-based-v1');
});

it('uses the configured provider when external AI is allowed (default)', function () {
    [, $org] = makeOrgUser('consent-on@example.com');
    $site = consentSite($org);

    $this->app->bind(AiProvider::class, function () {
        return new class implements AiProvider
        {
            public function generate(string $system, array $context, ?int $identityId = null): ?array
            {
                return ['title' => 'x', 'summary' => 'x', 'severity' => 'low', 'priority_score' => 1];
            }

            public function model(): string
            {
                return 'fake-consented-provider';
            }
        };
    });

    $insight = app(InsightGenerator::class)->summarize($site, DateRange::fromRequest('last_7_days', null, null, 'UTC'));

    expect($insight['model'])->toBe('fake-consented-provider');
});
