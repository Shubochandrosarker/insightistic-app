<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Idempotently promotes SUPER_ADMIN_EMAIL to super admin if that account
 * already exists. Safe to run on every deploy/seed — it only ever flips an
 * existing user's flag, never creates an account or touches a password, so
 * a misconfigured or absent SUPER_ADMIN_EMAIL is a silent no-op.
 *
 * New signups matching SUPER_ADMIN_EMAIL are already granted super admin at
 * registration time (see AuthController::register()); this seeder exists so
 * an account that registered BEFORE that env var was set (or before this
 * feature existed) gets healed on the next deploy, without anyone having to
 * remember to run `artisan insightistic:make-super-admin` by hand.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('insightistic.super_admin_email', '')));

        if ($email === '') {
            return;
        }

        $user = User::where('email', $email)->first();

        if (! $user || $user->is_super_admin) {
            return;
        }

        $user->forceFill(['is_super_admin' => true])->save();

        $this->command?->info("SuperAdminSeeder: promoted {$email} to super admin.");
    }
}
