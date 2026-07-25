<?php

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\KnowledgeRetriever;

/**
 * SECURITY REGRESSION SUITE — knowledge data must be invisible across
 * organizations at both the API layer and the retrieval layer.
 */

function seedIndexedSource(\App\Models\Organization $org, \App\Models\KnowledgeBase $base, string $content): KnowledgeSource
{
    $source = KnowledgeSource::withoutGlobalScope('organization')->create([
        'organization_id'   => $org->id,
        'knowledge_base_id' => $base->id,
        'type'              => 'manual',
        'title'             => 'Seeded',
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
        'token_count'         => 10,
    ]);

    return $source;
}

it('hides another organization\'s knowledge bases, sources and chunks from the API', function () {
    [$userA, $orgA] = makeOrgUser('iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('iso-b@example.com');

    $baseB = makeKnowledgeBase($orgB, 'B Secrets');
    $sourceB = seedIndexedSource($orgB, $baseB, 'The secret launch discount code is NEBULA42.');

    // A sees an empty list, and B's resources are 404 (not 403) for A.
    $this->actingAs($userA)->getJson('/api/knowledge-bases')->assertOk()->assertJsonCount(0, 'knowledge_bases');
    $this->actingAs($userA)->getJson("/api/knowledge-bases/{$baseB->id}")->assertNotFound();
    $this->actingAs($userA)->getJson("/api/knowledge-bases/{$baseB->id}/sources")->assertNotFound();
    $this->actingAs($userA)->getJson("/api/knowledge-sources/{$sourceB->id}")->assertNotFound();
    $this->actingAs($userA)->deleteJson("/api/knowledge-sources/{$sourceB->id}")->assertNotFound();
    $this->actingAs($userA)->postJson("/api/knowledge-bases/{$baseB->id}/test-retrieval", ['query' => 'discount'])
        ->assertNotFound();
});

it('never returns another organization\'s chunks from the retriever, even with forged filters', function () {
    [, $orgA] = makeOrgUser('iso-ret-a@example.com');
    [, $orgB] = makeOrgUser('iso-ret-b@example.com');

    $baseA = makeKnowledgeBase($orgA, 'A Docs');
    $baseB = makeKnowledgeBase($orgB, 'B Docs');
    seedIndexedSource($orgA, $baseA, 'Organization A ships worldwide with free returns.');
    $sourceB = seedIndexedSource($orgB, $baseB, 'Organization B secret pricing sheet: enterprise tier costs 9999.');

    $retriever = app(KnowledgeRetriever::class);

    // Search as org A, maliciously passing org B's kb + source ids as filters.
    $hits = $retriever->search($orgA->id, 'secret pricing enterprise', [
        'knowledge_base_ids' => [$baseB->id],
        'source_ids'         => [$sourceB->id],
    ]);

    expect($hits)->toBeEmpty();

    // Honest search in A's own scope still works.
    $own = $retriever->search($orgA->id, 'free returns shipping');
    expect($own)->not->toBeEmpty();
    expect($own->first()['chunk']->organization_id)->toBe($orgA->id);
});

it('keeps tenant isolation for sites (regression guard)', function () {
    [$userA] = makeOrgUser('site-iso-a@example.com');
    [, $orgB] = makeOrgUser('site-iso-b@example.com');

    $siteB = \App\Models\Site::withoutGlobalScope('organization')->create([
        'organization_id'  => $orgB->id,
        'name'             => 'B Store',
        'domain'           => 'b-store.example.com',
        'connector_key_id' => 'ik_' . \Illuminate\Support\Str::random(24),
    ]);

    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}")->assertNotFound();
    $this->actingAs($userA)->getJson("/api/sites/{$siteB->id}/analytics/overview")->assertNotFound();
});
