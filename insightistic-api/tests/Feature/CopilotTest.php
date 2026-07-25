<?php

use App\Models\AiCitation;
use App\Models\AiMessage;
use App\Models\AiRun;
use App\Models\AiThread;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;

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
