<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issue a signed, expiring, SINGLE-USE super-admin bootstrap token for a
 * named, already-registered user.
 *
 *   php artisan insightistic:super-admin:issue-token owner@example.com --ttl=15
 *
 * The raw token is printed exactly once. Only its SHA-256 hash is stored
 * (in cache, with the TTL), bound to the target user's id. The target user
 * redeems it while AUTHENTICATED via POST /api/auth/super-admin/redeem —
 * so possession of the token alone is not enough; the attacker would also
 * need the target account's session. Redemption burns the token.
 */
class IssueSuperAdminToken extends Command
{
    public const CACHE_PREFIX = 'super_admin_bootstrap:';

    protected $signature = 'insightistic:super-admin:issue-token
        {email : Email of the registered user this token may promote}
        {--ttl=15 : Token lifetime in minutes (1-60)}';

    protected $description = 'Issue a single-use, expiring bootstrap token that lets the named user self-promote to super admin.';

    public function __construct(private AuditLogger $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $ttl   = max(1, min(60, (int) $this->option('ttl')));

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}. They must register first.");
            return self::FAILURE;
        }
        if ($user->is_super_admin) {
            $this->info("{$email} is already a super admin — no token needed.");
            return self::SUCCESS;
        }
        if ($user->status !== 'active' || $user->archived_at !== null) {
            $this->error("{$email} is not an active account; refusing to issue a token.");
            return self::FAILURE;
        }

        $raw = 'sabt_' . Str::random(40);
        Cache::put(
            self::CACHE_PREFIX . hash('sha256', $raw),
            ['user_id' => $user->id, 'issued_at' => now()->toIso8601String()],
            now()->addMinutes($ttl),
        );

        $this->audit->logConsole('user.super_admin_token_issued', 'User', $user->id, null, ['ttl_minutes' => $ttl], 'artisan insightistic:super-admin:issue-token');

        $this->info("Single-use bootstrap token for {$email} (valid {$ttl} min):");
        $this->newLine();
        $this->line("  {$raw}");
        $this->newLine();
        $this->warn('Shown once. The user must be signed in as this account, then call');
        $this->warn('POST /api/auth/super-admin/redeem {"token": "..."} (or use the dashboard prompt).');

        return self::SUCCESS;
    }
}
