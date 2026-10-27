<?php

use App\Services\Knowledge\WpisticKnowledgeAgentClient;
use Illuminate\Support\Facades\Log;

it('keeps streaming when the shared brain and the log both fail', function () {
    [$user] = makeOrgUser('resilience@example.com');
    $brain = Mockery::mock(WpisticKnowledgeAgentClient::class);
    $brain->shouldReceive('search')->andThrow(new RuntimeException('unauthorized'));
    $this->app->instance(WpisticKnowledgeAgentClient::class, $brain);
    Log::shouldReceive('warning')->andThrow(new UnexpectedValueException('log not writable'));
    Log::shouldReceive('info', 'error', 'debug', 'channel', 'log')->andReturnNull();

    $id = $this->actingAs($user)->postJson('/api/copilot/threads', [])->json('thread.id');
    $stream = $this->actingAs($user)->post("/api/copilot/threads/{$id}/messages", [
        'content' => 'Summarize this month vs last month',
    ], ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();

    expect($stream)->toContain('event: run.completed');
});
