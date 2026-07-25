# Insightistic AI Knowledge Base

Tenant-isolated RAG (retrieval-augmented generation) platform. Organizations
upload documents, crawl their sites, or sync WordPress content; the Copilot
answers questions grounded in that content plus live analytics.

## Data model

| Table | Purpose |
|---|---|
| `knowledge_bases` | Named collection (org- or site-scoped). |
| `knowledge_sources` | One document/URL/sitemap/manual note/WP post. Carries type, checksum, status (`pending → processing → indexed | failed | disabled`), error_code/message, chunk_count, last_indexed_at. |
| `knowledge_chunks` | Retrieval units (~800 tokens, 15 % overlap, heading-aware). Columns: content, content_hash, token_count, `search_vector` (generated tsvector + GIN), `embedding vector(1536)` + HNSW (only when pgvector is installed), embedding_model. |
| `knowledge_ingestion_jobs` | Per-run telemetry: status, chunk/token counts, duration, error details. |

Every table carries `organization_id` (and nullable `site_id`); the
`BelongsToOrganization` global scope plus explicit SQL filters make
cross-tenant access structurally impossible.

## Ingestion pipeline (queued — `IngestKnowledgeSource`, tries=3, backoff 10/60/300s)

1. **Load** — file from the knowledge disk, URL/sitemap via the SSRF-guarded
   fetcher, manual/WordPress content from metadata.
2. **Extract** — PDF (smalot/pdfparser), DOCX (phpoffice/phpword), TXT/MD,
   CSV (header-aware "key: value" lines), JSON (flattened paths), HTML
   (DOM cleanup: scripts/nav/header/footer/aside removed, `<main>/<article>`
   preferred, converted to markdown). Robots `noindex` pages are refused.
3. **Chunk** — markdown-heading sections, paragraph packing, sentence
   fallback, ~800-token target, 15 % overlap.
4. **Dedup** — sha256 checksum: unchanged source ⇒ fast no-op; identical
   content already indexed in the same KB ⇒ `duplicate_content` failure.
5. **Embed** — batched (64) through the configured provider; metered
   against `monthly_embeddings`. Provider failure ⇒ chunks stored without
   vectors (FTS-only retrieval), never fabricated embeddings.
6. **Store** — atomic replace of the source's chunks (versioned re-index).

Failures always set `status=failed` + `error_code` (`blocked_url`,
`empty_content`, `duplicate_content`, `limit_reached`, `noindex`,
`missing_file`, `crawl_failed`, `worker_failed`, …) on both the source and
the ingestion job.

## Retrieval (`KnowledgeRetriever`)

Hybrid search, always org-filtered at SQL level:

- **Vector leg** — pgvector cosine (`embedding <=> query`) over the HNSW
  index, when the extension + an embedding provider are available.
- **FTS leg** — strict `websearch_to_tsquery` pass first, then a lenient
  OR-of-terms pass (natural-language questions rarely contain every content
  word).
- **Fusion** — reciprocal-rank fusion (k=60) across the candidate lists.

Filters: knowledge_base_ids, site_id, source_ids, types, indexed_after —
all validated server-side; ids belonging to another tenant simply drop out.

## SSRF policy (`SafeUrlFetcher`)

http/https only; ports 80/443 only; no URL credentials; internal hostnames
(localhost, *.local, *.internal, single-label) rejected before DNS; every
resolved A/AAAA record must be publicly routable (private, loopback,
link-local, CGN 100.64/10, 0/8, reserved, IPv6 ULA/link-local and
v4-mapped all rejected); the connection is pinned to the vetted IP
(DNS-rebinding-proof); redirects are never auto-followed — each hop is
re-vetted (max 3); 10 MB / 20 s caps. Sitemap crawls cap at 50 pages and
record skipped URLs in source metadata (no silent truncation).

## API

```
GET/POST      /api/knowledge-bases                    list+usage / create
GET/PATCH/DEL /api/knowledge-bases/{id}
GET/POST      /api/knowledge-bases/{id}/sources       list / add (file|url|sitemap|manual)
GET           /api/knowledge-sources/{id}             status + recent ingestion jobs
POST          /api/knowledge-sources/{id}/retry|reindex|disable
DELETE        /api/knowledge-sources/{id}             removes chunks; response reports remaining_chunks (verified 0)
POST          /api/knowledge-bases/{id}/test-retrieval
GET           /api/knowledge/usage
POST          /api/connector/v1/content/bulk          WordPress plugin push (HMAC)
```

Reads: owner/admin/analyst. Mutations: owner/admin. Uploads limited by
MIME + extension allowlists and size caps; every plan limit
(knowledge_bases, knowledge_sources, storage_mb, indexed_chunks,
monthly_embeddings) enforced server-side with structured
`402 {code:"limit_reached", limit:{key,max,used}}`.

## Configuration

See `docs/ENVIRONMENT-VARIABLES.md` → *AI Knowledge Base*. Summary:
`INSIGHTISTIC_EMBEDDINGS_PROVIDER` (`none`|`openai`), model/dimension
(canonical 1536), API key/base URL, knowledge disk (local or S3-compatible),
file/content/crawl caps. pgvector: install `postgresql-16-pgvector` or use
the `pgvector/pgvector:pg16` image (compose default); the migration
degrades to FTS-only retrieval when the extension cannot be created.
