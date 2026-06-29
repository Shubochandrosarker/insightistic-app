<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Promote (or create) the platform owner.
 *
 *   php artisan insightistic:make-super-admin owner@example.com
 *   php artisan insightistic:make-super-admin owner@example.com --create --name="Shuvo Sarkar" --password="strong-pass"
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'insightistic:make-super-admin
        {email : Email of the user to promote}
        {--create : Create the user if they do not exist}
        {--name= : Name to use when creating a new user}
        {--password= : Password to use when creating a new user (generated if omitted)}';

    protected $description = 'Mark a user as platform super admin (optionally creating them).';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("“{$email}” is not a valid email address.");
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

            $user = User::create([
                'name'           => $name,
                'email'          => $email,
                'password'       => $password, // hashed by cast
                'status'         => 'active',
                'is_super_admin' => true,
            ]);

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
        $this->info("{$email} is now a super admin. They can sign in and open /admin.");

        return self::SUCCESS;
    }
}
