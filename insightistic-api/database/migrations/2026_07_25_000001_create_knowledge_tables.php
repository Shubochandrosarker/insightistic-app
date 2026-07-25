<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insightistic AI Knowledge Base — core tables (spec Phase 4).
 *
 * pgvector: the migration attempts CREATE EXTENSION and, when available,
 * adds a `vector(1536)` embedding column + HNSW index to knowledge_chunks.
 * When the extension cannot be installed (managed Postgres without it, or a
 * DB user lacking the privilege) the schema still migrates — retrieval then
 * runs on PostgreSQL full-text search only, which KnowledgeRetriever detects
 * at runtime. Full-text search itself uses a generated tsvector column with
 * a GIN index in all cases.
 */
return new class extends Migration {
    public function up(): void
    {
        $hasVector = $this->ensureVectorExtension();

        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('scope', 20)->default('organization'); // organization|site|global
            $table->string('status', 20)->default('active');      // active|archived
            $table->jsonb('settings_json')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('site_id');
        });

        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            // file|url|sitemap|wordpress_post|product|manual|integration
            $table->string('type', 30);
            $table->string('title', 300);
            $table->text('source_url')->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->char('checksum', 64)->nullable(); // sha256 of raw bytes/content
            $table->unsignedBigInteger('size_bytes')->default(0);
            // pending|processing|indexed|failed|disabled
            $table->string('status', 20)->default('pending');
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('last_indexed_at')->nullable();
            $table->timestamps();

            $table->index(['knowledge_base_id', 'status']);
            $table->index(['organization_id', 'checksum']); // duplicate detection
            $table->index(['organization_id', 'status']);
            $table->index('site_id');
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->char('content_hash', 64);
            $table->unsignedInteger('token_count')->default(0);
            $table->jsonb('metadata_json')->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->timestamps();

            $table->unique(['knowledge_source_id', 'chunk_index']);
            $table->index('organization_id');
            $table->index(['organization_id', 'content_hash']);
        });

        // Full-text search: generated tsvector + GIN (always available).
        DB::statement("
            ALTER TABLE knowledge_chunks
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (to_tsvector('english', left(content, 100000))) STORED
        ");
        DB::statement('CREATE INDEX knowledge_chunks_search_idx ON knowledge_chunks USING GIN (search_vector)');

        // Vector similarity: only when pgvector is installed.
        if ($hasVector) {
            DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(1536)');
            DB::statement('CREATE INDEX knowledge_chunks_embedding_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)');
        }

        Schema::create('knowledge_ingestion_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_source_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued'); // queued|processing|completed|failed
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedBigInteger('token_count')->default(0);
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['knowledge_source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_ingestion_jobs');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_sources');
        Schema::dropIfExists('knowledge_bases');
        // The vector extension is left installed — other databases/apps may use it.
    }

    private function ensureVectorExtension(): bool
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

            return true;
        } catch (\Throwable $e) {
            // Not installable here (missing package or insufficient privilege).
            // Proceed without the embedding column — FTS-only retrieval.
            logger()->warning('pgvector unavailable; knowledge retrieval will use full-text search only.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
};
