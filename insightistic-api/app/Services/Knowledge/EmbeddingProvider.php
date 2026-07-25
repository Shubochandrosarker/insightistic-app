<?php

namespace App\Services\Knowledge;

/**
 * Turns text into vectors. Implementations MUST return either a real
 * embedding for every input or null for the whole batch — never fabricated
 * vectors. A null batch downgrades retrieval to full-text-only ranking,
 * which stays honest (real matches, no invented similarity).
 */
interface EmbeddingProvider
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>>|null  One vector per input, or null on failure/unconfigured.
     */
    public function embed(array $texts): ?array;

    public function model(): string;

    public function dimension(): int;
}
