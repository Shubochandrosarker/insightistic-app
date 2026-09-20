<?php

namespace App\Support;

/**
 * Deterministic keys for the SEO daily-grain tables (Phase 5 search
 * intelligence). SHA-1 keeps the unique-key columns at a fixed 40 chars
 * while staying collision-free for the cardinalities GSC actually returns.
 *
 * CRITICAL: writers AND readers must both go through this helper — a hash
 * recomputed by any other means would silently miss every row.
 */
class SeoGrain
{
    public static function hash(string $value): string
    {
        return hash('sha1', $value);
    }
}
