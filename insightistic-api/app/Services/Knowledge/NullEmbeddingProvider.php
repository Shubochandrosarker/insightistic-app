<?php

namespace App\Services\Knowledge;

/**
 * Bound when no embedding provider is configured. Never fabricates vectors:
 * ingestion still chunks and indexes content, and retrieval runs on
 * PostgreSQL full-text search alone — real results, honestly ranked.
 */
class NullEmbeddingProvider implements EmbeddingProvider
{
    public function embed(array $texts): ?array
    {
        return null;
    }

    public function model(): string
    {
        return 'none';
    }

    public function dimension(): int
    {
        return 1536;
    }
}
