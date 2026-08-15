<?php

namespace App\Query;

/**
 * Defense-in-depth check on a compiled query's SQL text, per
 * docs/04-LOCAL-QUERY-SECURITY.md's "never rely on keyword regex as the
 * primary SQL safety control" — this is NOT the primary control here.
 *
 * The primary control is architectural: every query this engine ever runs
 * is built exclusively by App\Query\Adapters\WooCommerceQueryCompiler using
 * Laravel's parameter-bound query builder against a hardcoded table/column
 * allowlist (WooCommerceQueryCompiler::ALLOWED_TABLES /
 * BASE_METRIC_COLUMNS) — no plan-supplied string is ever concatenated into
 * SQL text. This guard exists to catch a *future compiler bug*, not
 * untrusted input, which is why a regex-based check is acceptable here.
 *
 * Bindings are checked separately from SQL text: they are always passed to
 * PDO as parameters, never interpolated, so they cannot smuggle SQL — this
 * guard does not (and should not) inspect binding values.
 */
class ReadOnlySqlGuard
{
    /**
     * Whole-word, case-insensitive. Every statement type this engine must
     * never emit, plus catalog/administrative surfaces and delay/exfil
     * primitives called out in docs/04-LOCAL-QUERY-SECURITY.md.
     */
    private const FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'replace', 'merge', 'drop', 'alter', 'create',
        'truncate', 'grant', 'revoke', 'call', 'do', 'copy', 'vacuum', 'reindex',
        'load_file', 'outfile', 'dumpfile', 'benchmark', 'sleep', 'pg_sleep',
        'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir', 'xp_cmdshell',
        'information_schema', 'pg_catalog', 'pg_shadow', 'pg_authid',
        'union', 'exec', 'execute', 'into',
    ];

    /**
     * @param  string[]  $allowedTables
     */
    public function assertSafe(string $sql, array $allowedTables): void
    {
        $normalized = trim($sql);

        if ($normalized === '') {
            throw QueryEngineException::unsafeQuery('empty query');
        }

        if (substr_count($normalized, ';') > 0) {
            throw QueryEngineException::unsafeQuery('multiple statements');
        }

        if (str_contains($normalized, '--') || str_contains($normalized, '/*') || str_contains($normalized, '#')) {
            throw QueryEngineException::unsafeQuery('comment sequence present');
        }

        if (! preg_match('/^select\b/i', ltrim($normalized))) {
            throw QueryEngineException::unsafeQuery('must be a single SELECT statement');
        }

        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $normalized)) {
                throw QueryEngineException::unsafeQuery("forbidden keyword \"{$keyword}\"");
            }
        }

        foreach ($this->referencedTables($normalized) as $table) {
            if (! in_array($table, $allowedTables, true)) {
                throw QueryEngineException::unsafeQuery("unauthorized table \"{$table}\"");
            }
        }
    }

    /** @return string[] Table names following FROM/JOIN, stripped of quoting and alias. */
    private function referencedTables(string $sql): array
    {
        preg_match_all('/\b(?:from|join)\s+"?([a-zA-Z_][a-zA-Z0-9_]*)"?/i', $sql, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }
}
