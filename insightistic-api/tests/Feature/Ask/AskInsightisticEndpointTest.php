<?php

use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Site;

function askSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Ask Shop',
        'domain' => 'ask-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

beforeEach(function () {
    config(['database.connections.readonly.username' => null]);
});

it('answers a revenue question with a cited, evidence-backed answer', function () {
    [$user, $org] = makeOrgUser('ask-revenue@example.com');
    $site = askSite($org);
    MetricSnapshot::create([
        'site_id' => $site->id, 'date' => now()->toDateString(),
        'revenue' => 500, 'orders' => 5, 'refunds' => 0, 'average_order_value' => 100,
        'new_customers' => 2, 'returning_customers' => 3, 'products_sold' => 8, 'failed_orders' => 0,
    ]);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/ask", ['question' => 'What was our revenue this month?'])
        ->assertOk()
        ->json();

    expect($body['answer']['direct_answer'])->toContain('500')
        ->and($body['answer']['confidence'])->toBeIn(['high', 'medium', 'low'])
        ->and($body['answer']['citation_refs'])->toBe(["evidence_run:{$body['evidence_run_id']}"])
        ->and($body['chart']['type'])->toBe('kpi')
        ->and($body['evidence']['plan']['metrics'])->toBe(['net_revenue']);
});

it('answers a "top products" question with a bar chart breakdown', function () {
    [$user, $org] = makeOrgUser('ask-products@example.com');
    $site = askSite($org);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/ask", ['question' => 'What are our top 3 products this month?'])
        ->assertOk()
        ->json();

    expect($body['chart']['type'])->toBe('bar')
        ->and($body['evidence']['plan']['dimension'])->toBe('product')
        ->and($body['evidence']['plan']['limit'])->toBe(3);
});

it('returns 422 and records an evidence run when the question maps to no metric', function () {
    [$user, $org] = makeOrgUser('ask-nomatch@example.com');
    $site = askSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/ask", ['question' => 'What is the meaning of life?'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'question_not_understood');

    $runs = $this->actingAs($user)->getJson("/api/sites/{$site->id}/evidence")->assertOk()->json('runs');

    expect($runs)->toHaveCount(1)
        ->and($runs[0]['run_type'])->toBe('ask')
        ->and($runs[0]['status'])->toBe('error')
        ->and($runs[0]['error_code'])->toBe('question_not_understood');
});

it('rejects a missing or blank question without touching the query engine', function () {
    [$user, $org] = makeOrgUser('ask-blank@example.com');
    $site = askSite($org);

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/ask", ['question' => '   '])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_question');

    $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/ask", [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_question');
});

it('never lets an ask answer for a site outside the caller\'s organization', function () {
    [$userA, $orgA] = makeOrgUser('ask-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('ask-iso-b@example.com');
    $siteA = askSite($orgA);

    $this->actingAs($userB)
        ->postJson("/api/sites/{$siteA->id}/ask", ['question' => 'What was our revenue?'])
        ->assertNotFound();
});

it('falls back to the deterministic rule-based narration when external AI is disabled for the org', function () {
    [$user, $org] = makeOrgUser('ask-no-ai@example.com');
    $org->update(['allow_external_ai' => false]);
    $site = askSite($org);
    MetricSnapshot::create([
        'site_id' => $site->id, 'date' => now()->toDateString(),
        'revenue' => 250, 'orders' => 2, 'refunds' => 0, 'average_order_value' => 125,
        'new_customers' => 1, 'returning_customers' => 1, 'products_sold' => 3, 'failed_orders' => 0,
    ]);

    $body = $this->actingAs($user)
        ->postJson("/api/sites/{$site->id}/ask", ['question' => 'What was our revenue today?'])
        ->assertOk()
        ->json();

    // No AI provider is configured in the test environment either way, but
    // this asserts the *contract* stays deterministic-only when consent is
    // off — the evidence run must record ai_used = false.
    $run = $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/evidence/{$body['evidence_run_id']}")
        ->assertOk()
        ->json('run');

    expect($run['ai_used'])->toBeFalse();
});
