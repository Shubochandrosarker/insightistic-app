<?php

namespace App\Query;

use App\Query\Adapters\Ga4QueryCompiler;
use App\Query\Adapters\GscQueryCompiler;
use App\Query\Adapters\WooCommerceQueryCompiler;
use PDO;

/**
 * The actual DDL for provisioning the Safe Query Engine's read-only
 * Postgres role, extracted from the `db:provision-readonly-role` command so
 * it can also run against a deliberately raw, un-wrapped PDO connection in
 * tests (see tests/TestCase.php's `afterRefreshingDatabase()` docblock for
 * why that matters: PHPUnit\RefreshDatabase wraps every test in a
 * transaction it rolls back, which would silently undo a GRANT issued
 * through Laravel's own connection object).
 *
 * Grants SELECT on the union of every registered source adapter's
 * ALLOWED_TABLES (spec: P4 Multi-source Analytics added Ga4QueryCompiler
 * and GscQueryCompiler's tables here) — one role, one provisioning step,
 * whichever sources are actually connected for a given site.
 */
class ReadOnlyRoleProvisioner
{
    /** @return string[] Every table this role is granted SELECT on, across all registered source adapters. */
    public static function allowedTables(): array
    {
        return array_values(array_unique([
            ...WooCommerceQueryCompiler::ALLOWED_TABLES,
            ...Ga4QueryCompiler::ALLOWED_TABLES,
            ...GscQueryCompiler::ALLOWED_TABLES,
        ]));
    }

    /**
     * @return string[] Tables granted SELECT.
     */
    public function provision(PDO $pdo, string $database, string $role, string $password): array
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/', $role)) {
            throw new \InvalidArgumentException('Role name must be a plain identifier (letters, digits, underscore).');
        }

        $quotedPassword = $pdo->quote($password);

        $pdo->exec(<<<SQL
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE "{$role}" LOGIN;
                END IF;
            END
            \$\$;
        SQL);

        $pdo->exec("ALTER ROLE \"{$role}\" WITH LOGIN PASSWORD {$quotedPassword} NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;");
        $pdo->exec("GRANT CONNECT ON DATABASE \"{$database}\" TO \"{$role}\";");
        $pdo->exec("GRANT USAGE ON SCHEMA public TO \"{$role}\";");
        $pdo->exec("REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM \"{$role}\";");

        $tables = self::allowedTables();
        foreach ($tables as $table) {
            $pdo->exec("GRANT SELECT ON \"{$table}\" TO \"{$role}\";");
        }

        return $tables;
    }
}
