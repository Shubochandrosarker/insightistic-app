<?php

use App\Models\AiThread;

it('defines seven agents with disjoint sources and skills', function () {
    $agents = config('copilot_agents');
    expect($agents)->toHaveCount(7);
    $sources = collect($agents)->pluck('sources')->flatten();
    $skills = collect($agents)->pluck('skills')->flatten();
    expect($sources->duplicates())->toBeEmpty();
    expect($skills->duplicates())->toBeEmpty();
    foreach ($agents as $a) {
        expect($a['role'])->not->toBeEmpty();
        expect($a['starters'])->not->toBeEmpty();
        expect(array_diff($a['sources'], \App\Services\ContextAssembler::TYPES))->toBeEmpty();
    }
});

it('lists agents for the workspace', function () {
    [$user] = makeOrgUser('agents-list@example.com');
    $this->actingAs($user)->getJson('/api/copilot/agents')
        ->assertOk()->assertJsonCount(7, 'agents')->assertJsonPath('agents.0.key', 'seo');
});

it('applies the agent config to a message and stores it on the thread', function () {
    [$user] = makeOrgUser('agents-msg@example.com');
    $id = $this->actingAs($user)->postJson('/api/copilot/threads', [])->json('thread.id');

    $this->actingAs($user)->post("/api/copilot/threads/{$id}/messages", [
        'content' => 'Which articles should we improve first?',
        'agent'   => 'article',
    ], ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();

    $ctx = AiThread::withoutGlobalScopes()->find($id)->context_json;
    expect($ctx['agent'])->toBe('article');
    expect($ctx['sources'])->toBe(['content']);
});

it('rejects an unknown agent', function () {
    [$user] = makeOrgUser('agents-bad@example.com');
    $id = $this->actingAs($user)->postJson('/api/copilot/threads', [])->json('thread.id');
    $this->actingAs($user)->postJson("/api/copilot/threads/{$id}/messages", [
        'content' => 'hi', 'agent' => 'nope',
    ])->assertStatus(422);
});
