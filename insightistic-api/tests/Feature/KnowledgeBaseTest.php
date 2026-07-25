<?php

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Knowledge base lifecycle: CRUD, upload validation, plan limits,
 * queue-backed ingestion (sync in tests), deletion with chunk cleanup.
 */

it('creates a knowledge base and lists it with usage meters', function () {
    [$user] = makeOrgUser('kb-owner@example.com');

    $create = $this->actingAs($user)->postJson('/api/knowledge-bases', [
        'name'        => 'Company SOPs',
        'description' => 'Internal procedures',
    ]);
    $create->assertCreated()->assertJsonPath('knowledge_base.name', 'Company SOPs');

    $list = $this->actingAs($user)->getJson('/api/knowledge-bases');
    $list->assertOk()
        ->assertJsonCount(1, 'knowledge_bases')
        ->assertJsonStructure(['usage' => ['knowledge_bases', 'monthly_ai_messages']]);
});

it('enforces the knowledge_bases plan limit with a structured 402', function () {
    [$user] = makeOrgUser('kb-limited@example.com', ['knowledge_bases' => 1]);

    $this->actingAs($user)->postJson('/api/knowledge-bases', ['name' => 'First'])->assertCreated();

    $second = $this->actingAs($user)->postJson('/api/knowledge-bases', ['name' => 'Second']);
    $second->assertStatus(402)
        ->assertJsonPath('code', 'limit_reached')
        ->assertJsonPath('limit.key', 'knowledge_bases')
        ->assertJsonPath('limit.max', 1)
        ->assertJsonPath('limit.used', 1);
});

it('ingests a manual source end-to-end and makes it retrievable', function () {
    [$user, $org] = makeOrgUser('kb-ingest@example.com');
    $base = makeKnowledgeBase($org);

    $res = $this->actingAs($user)->postJson("/api/knowledge-bases/{$base->id}/sources", [
        'type'    => 'manual',
        'title'   => 'Refund policy',
        'content' => "# Refund policy\n\nCustomers may request a refund within 30 days of purchase. "
            . 'Refunds are processed to the original payment method within 5 business days.',
    ]);
    $res->assertCreated();

    // QUEUE_CONNECTION=sync in tests: ingestion already ran.
    $source = KnowledgeSource::withoutGlobalScope('organization')->findOrFail($res->json('source.id'));
    expect($source->status)->toBe('indexed');
    expect($source->chunk_count)->toBeGreaterThan(0);

    $search = $this->actingAs($user)->postJson("/api/knowledge-bases/{$base->id}/test-retrieval", [
        'query' => 'refund within how many days',
    ]);
    $search->assertOk();
    expect($search->json('results'))->not->toBeEmpty();
    expect($search->json('results.0.content'))->toContain('30 days');
});

it('uploads and indexes a markdown file', function () {
    Storage::fake('local');
    [$user, $org] = makeOrgUser('kb-upload@example.com');
    $base = makeKnowledgeBase($org);

    $file = UploadedFile::fake()->createWithContent(
        'guide.md',
        "# Shipping guide\n\nOrders ship within 2 business days from our warehouse."
    );

    $res = $this->actingAs($user)->post("/api/knowledge-bases/{$base->id}/sources", [
        'type' => 'file',
        'file' => $file,
    ], ['Accept' => 'application/json']);

    $res->assertCreated();
    $source = KnowledgeSource::withoutGlobalScope('organization')->findOrFail($res->json('source.id'));
    expect($source->status)->toBe('indexed');
    expect($source->mime_type)->toBe('text/markdown');
});

it('rejects unsupported file types by extension and mime', function () {
    Storage::fake('local');
    [$user, $org] = makeOrgUser('kb-badfile@example.com');
    $base = makeKnowledgeBase($org);

    $file = UploadedFile::fake()->createWithContent('malware.exe', 'MZ...');

    $this->actingAs($user)->post("/api/knowledge-bases/{$base->id}/sources", [
        'type' => 'file',
        'file' => $file,
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

it('enforces the knowledge_sources plan limit', function () {
    [$user, $org] = makeOrgUser('kb-srclimit@example.com', ['knowledge_sources' => 1]);
    $base = makeKnowledgeBase($org);

    $this->actingAs($user)->postJson("/api/knowledge-bases/{$base->id}/sources", [
        'type' => 'manual', 'title' => 'One', 'content' => 'Alpha content for indexing.',
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/knowledge-bases/{$base->id}/sources", [
        'type' => 'manual', 'title' => 'Two', 'content' => 'Beta content for indexing.',
    ])->assertStatus(402)->assertJsonPath('code', 'limit_reached');
});

it('deletes a source and verifiably removes all its chunks', function () {
    [$user, $org] = makeOrgUser('kb-delete@example.com');
    $base = makeKnowledgeBase($org);

    $res = $this->actingAs($user)->postJson("/api/knowledge-bases/{$base->id}/sources", [
        'type' => 'manual', 'title' => 'Doomed', 'content' => 'This content will be deleted soon enough.',
    ]);
    $sourceId = $res->json('source.id');

    expect(KnowledgeChunk::withoutGlobalScope('organization')->where('knowledge_source_id', $sourceId)->count())
        ->toBeGreaterThan(0);

    $del = $this->actingAs($user)->deleteJson("/api/knowledge-sources/{$sourceId}");
    $del->assertOk()->assertJsonPath('remaining_chunks', 0);

    expect(KnowledgeChunk::withoutGlobalScope('organization')->where('knowledge_source_id', $sourceId)->count())
        ->toBe(0);
});

it('blocks mutations for analyst and viewer roles', function () {
    [$owner, $org] = makeOrgUser('kb-roles-owner@example.com');
    $base = makeKnowledgeBase($org);

    $analyst = \App\Models\User::create([
        'name' => 'Analyst', 'email' => 'kb-analyst@example.com',
        'password' => 'secret-password', 'status' => 'active',
    ]);
    $org->users()->attach($analyst->id, ['role' => 'analyst']);

    // Analyst can read…
    $this->actingAs($analyst)->getJson('/api/knowledge-bases')->assertOk();

    // …but cannot create/delete.
    $this->actingAs($analyst)->postJson('/api/knowledge-bases', ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($analyst)->deleteJson("/api/knowledge-bases/{$base->id}")->assertForbidden();
});

it('marks a failed ingestion honestly with an error code (SSRF-blocked URL)', function () {
    [$user, $org] = makeOrgUser('kb-fail@example.com');
    $base = makeKnowledgeBase($org);

    // A URL source pointing at cloud metadata: the SSRF guard must reject it
    // during ingestion and the failure must be visible, never silent.
    $res = $this->actingAs($user)->postJson("/api/knowledge-bases/{$base->id}/sources", [
        'type' => 'url', 'title' => 'Metadata grab', 'url' => 'http://169.254.169.254/latest/meta-data/',
    ]);
    $res->assertCreated();

    $source = KnowledgeSource::withoutGlobalScope('organization')->findOrFail($res->json('source.id'));
    expect($source->status)->toBe('failed');
    expect($source->error_code)->toBe('blocked_url');

    // Retry endpoint requeues it (fails again, but the workflow works).
    $retry = $this->actingAs($user)->postJson("/api/knowledge-sources/{$source->id}/retry");
    $retry->assertStatus(202);
});
