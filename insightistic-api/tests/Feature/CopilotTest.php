<?php

use App\Models\AiCitation;
use App\Models\AiMessage;
use App\Models\AiRun;
use App\Models\AiThread;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Http;

/**
 * Copilot: thread lifecycle, SSE streaming (rule provider — offline),
 * genuine citations from tenant-owned chunks, quota enforcement,
 * cross-tenant and cross-user isolation.
 */

function seedKnowledge(\App\Models\Organization $org, string $content): KnowledgeSource
{
    $base = makeKnowledgeBase($org, 'Copilot KB');
    $source = KnowledgeSource::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'knowledge_base_id' => $base->id,
        'type'              => 'manual',
        'title'             => 'Copilot doc',
        'status'            => 'indexed',
        'chunk_count'       => 1,
        'last_indexed_at'   => now(),
    ]);
    KnowledgeChunk::withoutGlobalScope('organization')->create([
        'organization_id'     => $org->id,
        'knowledge_source_id' => $source->id,
        'chunk_index'         => 0,
        'content'             => $content,
        'content_hash'        => hash('sha256', $content),
        'token_count'         => 20,
    ]);

    return $source;
}

it('creates a thread, streams an answer and stores real citations', function () {
    [$user, $org] = makeOrgUser('copilot@example.com');
    seedKnowledge($org, 'Our warranty covers manufacturing defects for 24 months from delivery.');

    $thread = $this->actingAs($user)->postJson('/api/copilot/threads', []);
    $thread->assertCreated();
    $threadId = $thread->json('thread.id');

    $response = $this->actingAs($user)->post("/api/copilot/threads/{$threadId}/messages", [
        'content' => 'How long is the warranty coverage?',
    ], ['Accept' => 'text/event-stream']);

    $response->assertOk();
    $stream = $response->streamedContent();

    expect($stream)->toContain('event: run.started');
    expect($stream)->toContain('event: delta');
    expect($stream)->toContain('event: citations');
    expect($stream)->toContain('event: run.completed');

    // Assistant message persisted with the answer contract.
    $assistant = AiMessage::withoutGlobalScope('organization')
        ->where('ai_thread_id', $threadId)->where('role', 'assistant')->firstOrFail();
    expect($assistant->status)->toBe('completed');
    expect($assistant->answer_json)->toBeArray();

    // Citations reference THIS org's chunks only — never invented.
    $citations = AiCitation::withoutGlobalScope('organization')
        ->where('ai_message_id', $assistant->id)->get();
    expect($citations)->not->toBeEmpty();
    foreach ($citations->whereNotNull('knowledge_chunk_id') as $citation) {
        $chunk = KnowledgeChunk::withoutGlobalScope('organization')->find($citation->knowledge_chunk_id);
        expect($chunk->organization_id)->toBe($org->id);
    }

    // Run telemetry recorded (provider/model/latency).
    $run = AiRun::withoutGlobalScope('organization')->where('ai_message_id', $assistant->id)->firstOrFail();
    expect($run->provider)->toBe('rule');
    expect($run->latency_ms)->not->toBeNull();

    // Thread title auto-set from the first question.
    expect(AiThread::withoutGlobalScope('organization')->find($threadId)->title)
        ->toContain('warranty');
});

it('uses the WPistic product gateway before the free OpenRouter route', function () {
    [$user, $org] = makeOrgUser('copilot-wpistic-gateway@example.com');
    $org->forceFill(['allow_external_ai' => true])->save();

    config()->set('insightistic.wpistic_gateway', [
        'base_url'    => 'https://ai.wpistic.com',
        'chat_path'   => '/compat/chat/completions',
        'product_key' => 'test-product-key',
        'product_id'  => 'insightistic',
        'model'       => 'glm-5.3',
        'max_tokens'  => 1200,
        'timeout'     => 30,
    ]);
    config()->set('insightistic.openrouter.api_key', null);

    $content = <<<'ANSWER'
WPistic gateway response.

```json
{"direct_answer":"Connected","key_finding":null,"supporting_metrics":[],"why_it_matters":"The gateway responded.","recommended_actions":[],"confidence":"high","data_limitations":"No business data used","citation_refs":[]}
```
ANSWER;

    Http::fake([
        'https://ai.wpistic.com/compat/chat/completions' => Http::response([
            'model'   => 'z-ai/glm-5.3',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage'   => ['prompt_tokens' => 120, 'completion_tokens' => 45],
        ]),
    ]);

    $threadId = $this->actingAs($user)->postJson('/api/copilot/threads', [])->assertCreated()->json('thread.id');
    $response = $this->actingAs($user)->post("/api/copilot/threads/{$threadId}/messages", [
        'content' => 'Confirm the AI connection without using business data.',
    ], ['Accept' => 'text/event-stream']);
    $response->assertOk();
    $stream = $response->streamedContent();

    $assistant = AiMessage::withoutGlobalScope('organization')
        ->where('ai_thread_id', $threadId)->where('role', 'assistant')->firstOrFail();
    $run = AiRun::withoutGlobalScope('organization')->where('ai_message_id', $assistant->id)->firstOrFail();

    expect($assistant->content)->toContain('WPistic gateway response.')
        ->and($assistant->answer_json['direct_answer'])->toBe('Connected')
        ->and($run->provider)->toBe('wpistic_gateway')
        ->and($run->model)->toBe('wpistic-gateway:z-ai/glm-5.3');
    expect($stream)->toContain('event: run.completed');

    Http::assertSent(fn ($request) =>
        $request->hasHeader('X-Wpistic-Product', 'insightistic')
        && $request->hasHeader('X-Wpistic-User', 'org-' . $org->id)
        && $request['model'] === 'glm-5.3'
    );
});

it('uses low reasoning and compatible free endpoints for Copilot', function () {
    [$user, $org] = makeOrgUser('copilot-free-openrouter@example.com');
    $org->forceFill(['allow_external_ai' => true])->save();

    config()->set('insightistic.wpistic_gateway.product_key', null);
    config()->set('insightistic.openrouter.api_key', 'test-openrouter-key');
    config()->set('insightistic.openrouter.base_url', 'https://openrouter.ai/api/v1');
    config()->set('insightistic.openrouter.free_model', 'openrouter/free');
    config()->set('insightistic.openrouter.free_reasoning_effort', 'low');
    config()->set('insightistic.openrouter.free_fallback_models', [
        'nvidia/nemotron-3-super-120b-a12b:free',
        'liquid/lfm-2.5-2.6b:free',
    ]);

    $answer = json_encode([
        'id' => 'chatcmpl-free',
        'model' => 'nvidia/nemotron-3-super-120b-a12b:free',
        'choices' => [[
            'delta' => ['content' => 'The free model is responding.'],
            'finish_reason' => null,
        ]],
    ], JSON_UNESCAPED_SLASHES);

    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response(
            "data: {$answer}\n\ndata: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $threadId = $this->actingAs($user)->postJson('/api/copilot/threads', [])->assertCreated()->json('thread.id');
    $response = $this->actingAs($user)->post("/api/copilot/threads/{$threadId}/messages", [
        'content' => 'Confirm the free AI route without using business data.',
    ], ['Accept' => 'text/event-stream']);

    $response->assertOk();
    expect($response->streamedContent())->toContain('The free model is responding.');

    Http::assertSent(fn ($request) => $request['model'] === 'openrouter/free'
        && $request['models'] === [
            'nvidia/nemotron-3-super-120b-a12b:free',
            'liquid/lfm-2.5-2.6b:free',
        ]
        && $request['reasoning']['effort'] === 'low'
        && $request['provider']['require_parameters'] === true
    );
});

it('enforces the monthly AI message quota with a structured 402', function () {
    [$user, $org] = makeOrgUser('copilot-quota@example.com', ['monthly_ai_messages' => 0]);

    $threadId = $this->actingAs($user)->postJson('/api/copilot/threads', [])->json('thread.id');

    $res = $this->actingAs($user)->postJson("/api/copilot/threads/{$threadId}/messages", [
        'content' => 'Anything?',
    ]);

    $res->assertStatus(402)->assertJsonPath('code', 'limit_reached')
        ->assertJsonPath('limit.key', 'monthly_ai_messages');

    expect(AiMessage::withoutGlobalScope('organization')->count())->toBe(0);
});

it('meters usage only on successful runs', function () {
    [$user, $org] = makeOrgUser('copilot-meter@example.com', ['monthly_ai_messages' => 5]);

    $threadId = $this->actingAs($user)->postJson('/api/copilot/threads', [])->json('thread.id');
    $this->actingAs($user)->post("/api/copilot/threads/{$threadId}/messages", [
        'content' => 'Count me.',
    ], ['Accept' => 'text/event-stream'])->streamedContent();

    expect((int) \App\Models\UsageCounter::currentFor($org)->ai_messages_used)->toBe(1);
});

it('isolates threads between users and organizations', function () {
    [$userA, $orgA] = makeOrgUser('copilot-a@example.com');
    [$userB] = makeOrgUser('copilot-b@example.com');

    $threadId = $this->actingAs($userA)->postJson('/api/copilot/threads', [])->json('thread.id');

    // Another org's user: 404.
    $this->actingAs($userB)->getJson("/api/copilot/threads/{$threadId}")->assertNotFound();
    $this->actingAs($userB)->deleteJson("/api/copilot/threads/{$threadId}")->assertNotFound();

    // A teammate in the SAME org but different user: also 404 (personal threads).
    $teammate = \App\Models\User::create([
        'name' => 'Mate', 'email' => 'copilot-mate@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $orgA->users()->attach($teammate->id, ['role' => 'admin']);
    $this->actingAs($teammate)->getJson("/api/copilot/threads/{$threadId}")->assertNotFound();
});

it('deletes a thread with its messages, citations and feedback', function () {
    [$user, $org] = makeOrgUser('copilot-del@example.com');
    seedKnowledge($org, 'Deletable knowledge about shipping times of 3 days.');

    $threadId = $this->actingAs($user)->postJson('/api/copilot/threads', [])->json('thread.id');
    $this->actingAs($user)->post("/api/copilot/threads/{$threadId}/messages", [
        'content' => 'What are the shipping times?',
    ], ['Accept' => 'text/event-stream'])->streamedContent();

    $messageIds = AiMessage::withoutGlobalScope('organization')
        ->where('ai_thread_id', $threadId)->pluck('id');
    expect($messageIds)->not->toBeEmpty();

    $this->actingAs($user)->deleteJson("/api/copilot/threads/{$threadId}")->assertOk();

    expect(AiMessage::withoutGlobalScope('organization')->whereIn('id', $messageIds)->count())->toBe(0);
    expect(AiCitation::withoutGlobalScope('organization')->whereIn('ai_message_id', $messageIds)->count())->toBe(0);
});

it('records feedback once per user per message', function () {
    [$user, $org] = makeOrgUser('copilot-fb@example.com');

    $threadId = $this->actingAs($user)->postJson('/api/copilot/threads', [])->json('thread.id');
    $this->actingAs($user)->post("/api/copilot/threads/{$threadId}/messages", [
        'content' => 'Feedback test question.',
    ], ['Accept' => 'text/event-stream'])->streamedContent();

    $assistant = AiMessage::withoutGlobalScope('organization')
        ->where('ai_thread_id', $threadId)->where('role', 'assistant')->firstOrFail();

    $this->actingAs($user)->postJson("/api/copilot/messages/{$assistant->id}/feedback", ['rating' => 1])
        ->assertOk();
    // Update, not duplicate.
    $this->actingAs($user)->postJson("/api/copilot/messages/{$assistant->id}/feedback", ['rating' => -1, 'comment' => 'meh'])
        ->assertOk();

    expect(\App\Models\AiFeedback::withoutGlobalScope('organization')
        ->where('ai_message_id', $assistant->id)->count())->toBe(1);
});
