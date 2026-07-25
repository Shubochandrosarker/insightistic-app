<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Promote (or create) the platform owner. Server-shell access required —
 * this is the ONLY unauthenticated way to obtain super admin, by design.
 *
 *   php artisan insightistic:make-super-admin owner@example.com
 *   php artisan insightistic:make-super-admin owner@example.com --create --name="Shuvo Sarkar" --password="strong-pass"
 *
 * Once at least one active super admin exists, further promotions should go
 * through POST /api/admin/users/{id}/promote (audited, requires an existing
 * super admin). This command then refuses unless --force is passed, so a
 * compromised deploy pipeline can't quietly mint admins as a matter of course.
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'insightistic:make-super-admin
        {email : Email of the user to promote}
        {--create : Create the user if they do not exist}
        {--name= : Name to use when creating a new user}
        {--password= : Password to use when creating a new user (generated if omitted)}
        {--force : Allow promotion even though an active super admin already exists}';

    protected $description = 'Mark a user as platform super admin (optionally creating them).';

    public function __construct(private AuditLogger $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("“{$email}” is not a valid email address.");
            return self::FAILURE;
        }

        $existingSuperAdmins = User::where('is_super_admin', true)
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->count();

        if ($existingSuperAdmins > 0 && ! $this->option('force')) {
            $this->error('An active super admin already exists.');
            $this->line('Promote further admins from the dashboard (POST /api/admin/users/{id}/promote),');
            $this->line('or issue a single-use bootstrap token: php artisan insightistic:super-admin:issue-token');
            $this->line('To override anyway (break-glass), re-run with --force.');
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            if (! $this->option('create')) {
                $this->error("No user found with email {$email}. Re-run with --create to make one.");
                return self::FAILURE;
            }

            $password = (string) ($this->option('password') ?: Str::password(16));
            $name     = (string) ($this->option('name') ?: Str::before($email, '@'));

            $validator = Validator::make(['password' => $password], ['password' => [Password::min(8)]]);
            if ($validator->fails()) {
                $this->error('Password must be at least 8 characters.');
                return self::FAILURE;
            }

            $user = new User([
                'name'     => $name,
                'email'    => $email,
                'password' => $password, // hashed by cast
                'status'   => 'active',
            ]);
            $user->forceFill(['is_super_admin' => true])->save();

            $this->audit->logConsole('user.bootstrap_super_admin', 'User', $user->id, null, ['is_super_admin' => true, 'created' => true], 'artisan insightistic:make-super-admin --create');

            $this->info("Created super admin {$email}.");
            if (! $this->option('password')) {
                $this->warn("Generated password: {$password}");
                $this->warn('Store it now — it will not be shown again.');
            }

            return self::SUCCESS;
        }

        if ($user->is_super_admin) {
            $this->info("{$email} is already a super admin.");
            return self::SUCCESS;
        }

        $user->forceFill(['is_super_admin' => true])->save();

        $this->audit->logConsole('user.promote_super_admin', 'User', $user->id, ['is_super_admin' => false], ['is_super_admin' => true], 'artisan insightistic:make-super-admin' . ($this->option('force') ? ' --force' : ''));

        $this->info("{$email} is now a super admin. They can sign in and open /admin.");

        return self::SUCCESS;
    }
}
