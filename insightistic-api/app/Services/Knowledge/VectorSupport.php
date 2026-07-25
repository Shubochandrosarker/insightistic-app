<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Schema;

/**
 * Runtime detection of the pgvector-backed embedding column. The migration
 * degrades gracefully when the extension is unavailable (FTS-only); every
 * embedding read/write MUST consult this first.
 */
class VectorSupport
{
    private static ?bool $enabled = null;

    public static function enabled(): bool
    {
        return self::$enabled ??= Schema::hasColumn('knowledge_chunks', 'embedding');
    }

    /** For tests. */
    public static function reset(): void
    {
        self::$enabled = null;
    }

    /** pgvector literal format: '[0.1,0.2,...]'. */
    public static function toLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(static fn ($v) => (string) (float) $v, $vector)) . ']';
    }
}
