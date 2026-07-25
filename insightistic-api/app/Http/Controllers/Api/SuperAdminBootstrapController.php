<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\IssueSuperAdminToken;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Redeems a single-use super-admin bootstrap token issued by
 * `php artisan insightistic:super-admin:issue-token` (see that command for
 * the threat model). The caller must be AUTHENTICATED as the exact user the
 * token was issued for — the token alone never grants anything.
 */
class SuperAdminBootstrapController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function redeem(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();

        // Pull = read AND delete atomically: a token is burned by its first
        // redemption attempt that reaches this line, valid or not for this
        // user — replaying a stolen token after the owner used it is useless.
        $payload = Cache::pull(IssueSuperAdminToken::CACHE_PREFIX . hash('sha256', $data['token']));

        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $user->id) {
            $this->audit->logConsole('user.super_admin_token_rejected', 'User', $user->id, null, null, 'invalid, expired or mismatched bootstrap token');

            return response()->json(['message' => 'Invalid or expired bootstrap token.'], 422);
        }

        if (! $user->is_super_admin) {
            $user->forceFill(['is_super_admin' => true])->save();
        }

        $this->audit->log($request, 'user.super_admin_token_redeemed', 'User', $user->id, ['is_super_admin' => false], ['is_super_admin' => true], 'bootstrap token redeemed');

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'is_super_admin']),
        ]);
    }
}
