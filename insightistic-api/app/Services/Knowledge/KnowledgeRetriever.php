<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-isolated hybrid retrieval (spec Phase 4.2).
 *
 * SECURITY INVARIANT: every SQL path filters on the organization_id the
 * CALLER'S SERVER-SIDE CONTEXT supplies — the value never comes from the
 * browser, and there is no code path that searches without it
 * (tests/Feature/KnowledgeRetrievalTest.php proves cross-tenant isolation).
 *
 * Ranking: vector similarity (pgvector cosine) and PostgreSQL full-text
 * search run as two candidate lists, merged with reciprocal-rank fusion.
 * With no embeddings available (extension or provider missing) retrieval is
 * FTS-only — degraded recall, never degraded isolation.
 */
class KnowledgeRetriever
{
    private const RRF_K = 60;

    public function __construct(private EmbeddingProvider $embeddings) {}

    /**
     * @param array{
     *   knowledge_base_ids?: int[], site_id?: ?int, source_ids?: int[],
     *   types?: string[], indexed_after?: ?string, limit?: int
     * } $options
     * @return Collection<int, array{chunk: KnowledgeChunk, score: float, rank_sources: string[]}>
     */
    public function search(int $organizationId, string $query, array $options = []): Collection
    {
        $limit = min(20, max(1, (int) ($options['limit'] ?? 8)));
        $candidateLimit = $limit * 4;

        $ftsRanks = $this->fullTextCandidates($organizationId, $query, $options, $candidateLimit);
        $vecRanks = $this->vectorCandidates($organizationId, $query, $options, $candidateLimit);

        // Reciprocal-rank fusion across both lists.
        $scores = [];
        $origins = [];
        foreach (['fts' => $ftsRanks, 'vector' => $vecRanks] as $origin => $ranked) {
            foreach (array_values($ranked) as $rank => $chunkId) {
                $scores[$chunkId] = ($scores[$chunkId] ?? 0.0) + 1.0 / (self::RRF_K + $rank + 1);
                $origins[$chunkId][] = $origin;
            }
        }

        if ($scores === []) {
            return collect();
        }

        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, $limit);

        // Re-assert tenancy on the hydration query as well (defense in depth).
        $chunks = KnowledgeChunk::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereIn('id', $topIds)
            ->with('source:id,knowledge_base_id,title,type,source_url,last_indexed_at')
            ->get()
            ->keyBy('id');

        return collect($topIds)
            ->filter(fn ($id) => $chunks->has($id))
            ->map(fn ($id) => [
                'chunk'        => $chunks[$id],
                'score'        => round($scores[$id], 6),
                'rank_sources' => array_values(array_unique($origins[$id])),
            ])
            ->values();
    }

    /**
     * Two FTS passes: strict websearch semantics (ANDs every term — high
     * precision) first, then a lenient OR query over the significant words
     * (natural-language questions rarely contain every content word, e.g.
     * "refund within how many days" must still match a chunk that lacks
     * "many"). ts_rank orders the lenient pass so multi-term matches win.
     *
     * @return list<int> chunk ids, best first
     */
    private function fullTextCandidates(int $organizationId, string $query, array $options, int $limit): array
    {
        [$filterSql, $bindings] = $this->filterClause($organizationId, $options);

        $run = function (string $tsquerySql, string $queryParam) use ($filterSql, $bindings, $limit): array {
            $rows = DB::select(
                "SELECT kc.id
                   FROM knowledge_chunks kc
                   JOIN knowledge_sources ks ON ks.id = kc.knowledge_source_id
                  WHERE {$filterSql}
                    AND ks.status != 'disabled'
                    AND kc.search_vector @@ {$tsquerySql}
                  ORDER BY ts_rank(kc.search_vector, {$tsquerySql}) DESC
                  LIMIT {$limit}",
                [...$bindings, $queryParam, $queryParam],
            );

            return array_map(static fn ($r) => (int) $r->id, $rows);
        };

        $ids = $run("websearch_to_tsquery('english', ?)", $query);

        if (count($ids) < $limit && ($orQuery = $this->orQuery($query)) !== null) {
            foreach ($run("to_tsquery('english', ?)", $orQuery) as $id) {
                if (! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return array_slice($ids, 0, $limit);
    }

    /** "refund within how many days" → "refund | within | how | many | days" (sanitized). */
    private function orQuery(string $query): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 2)));

        if ($words === []) {
            return null;
        }

        return implode(' | ', array_slice($words, 0, 16));
    }

    /** @return list<int> chunk ids, best first */
    private function vectorCandidates(int $organizationId, string $query, array $options, int $limit): array
    {
        if (! VectorSupport::enabled()) {
            return [];
        }

        $vectors = $this->embeddings->embed([$query]);
        if ($vectors === null || ! isset($vectors[0])) {
            return [];
        }

        [$filterSql, $bindings] = $this->filterClause($organizationId, $options);
        $literal = VectorSupport::toLiteral($vectors[0]);

        $rows = DB::select(
            "SELECT kc.id
               FROM knowledge_chunks kc
               JOIN knowledge_sources ks ON ks.id = kc.knowledge_source_id
              WHERE {$filterSql}
                AND ks.status != 'disabled'
                AND kc.embedding IS NOT NULL
              ORDER BY kc.embedding <=> ?::vector
              LIMIT {$limit}",
            [...$bindings, $literal],
        );

        return array_map(static fn ($r) => (int) $r->id, $rows);
    }

    /**
     * Shared, fully parameterized filter clause. organization_id is ALWAYS
     * the first predicate.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function filterClause(int $organizationId, array $options): array
    {
        $sql = 'kc.organization_id = ?';
        $bindings = [$organizationId];

        if (! empty($options['knowledge_base_ids'])) {
            $ids = array_map('intval', (array) $options['knowledge_base_ids']);
            $sql .= ' AND ks.knowledge_base_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($bindings, ...$ids);
        }

        if (array_key_exists('site_id', $options) && $options['site_id'] !== null) {
            $sql .= ' AND (kc.site_id = ? OR kc.site_id IS NULL)';
            $bindings[] = (int) $options['site_id'];
        }

        if (! empty($options['source_ids'])) {
            $ids = array_map('intval', (array) $options['source_ids']);
            $sql .= ' AND kc.knowledge_source_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($bindings, ...$ids);
        }

        if (! empty($options['types'])) {
            $types = array_values(array_intersect((array) $options['types'], \App\Models\KnowledgeSource::TYPES));
            if ($types !== []) {
                $sql .= ' AND ks.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
                array_push($bindings, ...$types);
            }
        }

        if (! empty($options['indexed_after'])) {
            $sql .= ' AND ks.last_indexed_at >= ?';
            $bindings[] = $options['indexed_after'];
        }

        return [$sql, $bindings];
    }
}
