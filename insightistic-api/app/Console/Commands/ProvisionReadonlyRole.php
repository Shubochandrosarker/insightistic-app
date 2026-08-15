<?php

namespace App\Console\Commands;

use App\Query\ReadOnlyRoleProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time (idempotent, safe to re-run) provisioning for the Safe
 * WooCommerce Query Engine's read-only Postgres role (spec: "read-only
 * identity must be unable to write or alter schema even if every
 * application validator fails"). Run this using the DEFAULT connection's
 * credentials (which must own/administer the database) — it never runs
 * automatically from a web request.
 *
 * After running, set READONLY_DB_USERNAME / READONLY_DB_PASSWORD in the
 * environment to the values used here (see docs/SAFE-QUERY-ENGINE.md).
 */
class ProvisionReadonlyRole extends Command
{
    protected $signature = 'db:provision-readonly-role
        {--role= : Role name (defaults to READONLY_DB_USERNAME env)}
        {--password= : Role password (defaults to READONLY_DB_PASSWORD env)}';

    protected $description = 'Provision (or update) the least-privilege Postgres role the Safe Query Engine executes under';

    public function handle(ReadOnlyRoleProvisioner $provisioner): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('This command only supports PostgreSQL.');

            return self::FAILURE;
        }

        $role = $this->option('role') ?: env('READONLY_DB_USERNAME');
        $password = $this->option('password') ?: env('READONLY_DB_PASSWORD');

        if (! $role) {
            $this->error('No role name given (pass --role or set READONLY_DB_USERNAME).');

            return self::FAILURE;
        }

        if (! $password) {
            $this->error('No password given (pass --password or set READONLY_DB_PASSWORD).');

            return self::FAILURE;
        }

        try {
            $granted = $provisioner->provision(
                DB::connection()->getPdo(),
                DB::connection()->getDatabaseName(),
                $role,
                $password,
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($granted as $table) {
            $this->line("Granted SELECT on {$table}");
        }

        $this->info("Role \"{$role}\" provisioned: SELECT-only on [".implode(', ', $granted).'].');
        $this->comment('Set READONLY_DB_USERNAME / READONLY_DB_PASSWORD in the environment to activate it.');

        return self::SUCCESS;
    }
}
