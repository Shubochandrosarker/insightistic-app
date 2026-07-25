<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DEPRECATED — intentionally a no-op.
 *
 * This seeder used to promote whichever account matched the SUPER_ADMIN_EMAIL
 * env var on every deploy. That meant anyone who registered the configured
 * address (or could influence the env) gained platform-wide admin — a
 * privilege-escalation vector, removed in the security hardening pass.
 *
 * Create super admins explicitly instead:
 *   php artisan insightistic:make-super-admin owner@example.com [--create]
 *   php artisan insightistic:super-admin:issue-token owner@example.com
 * or promote from the dashboard (POST /api/admin/users/{id}/promote).
 *
 * The class is kept (as a no-op) so existing deploy scripts that call
 * `db:seed --class=SuperAdminSeeder` keep working while operators migrate.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn(
            'SuperAdminSeeder is deprecated and does nothing. ' .
            'Use `php artisan insightistic:make-super-admin` instead (see docs/SUPER-ADMIN.md).'
        );
    }
}
