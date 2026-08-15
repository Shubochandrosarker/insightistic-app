<?php

namespace App\Query;

use App\Query\Adapters\WooCommerceQueryCompiler;
use PDO;

/**
 * The actual DDL for provisioning the Safe WooCommerce Query Engine's
 * read-only Postgres role, extracted from the `db:provision-readonly-role`
 * command so it can also run against a deliberately raw, un-wrapped PDO
 * connection in tests (see tests/TestCase.php's `afterRefreshingDatabase()`
 * docblock for why that matters: PHPUnit\RefreshDatabase wraps every test
 * in a transaction it rolls back, which would silently undo a GRANT issued
 * through Laravel's own connection object).
 */
class ReadOnlyRoleProvisioner
{
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

        foreach (WooCommerceQueryCompiler::ALLOWED_TABLES as $table) {
            $pdo->exec("GRANT SELECT ON \"{$table}\" TO \"{$role}\";");
        }

        return WooCommerceQueryCompiler::ALLOWED_TABLES;
    }
}
