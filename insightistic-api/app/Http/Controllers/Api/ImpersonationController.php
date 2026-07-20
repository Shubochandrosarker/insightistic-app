<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImpersonationSession;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * The "clear exit" half of impersonation (spec §13.9). Called by the
 * impersonated session's own token — auth:sanctum only, no `tenant` or
 * `super_admin` gate, since by definition the caller is acting as the
 * target user, not the admin. See Admin\ImpersonationController::start().
 */
class ImpersonationController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** POST /impersonation/end */
    public function end(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        $abilities = $token?->abilities ?? [];

        if (! in_array('impersonated', $abilities, true)) {
            return response()->json(['message' => 'Not an impersonation session.'], 422);
        }

        $session = ImpersonationSession::where('personal_access_token_id', $token->id)
            ->whereNull('ended_at')
            ->first();

        if ($session) {
            $session->update(['ended_at' => now()]);
            $this->audit->log($request, 'impersonation.end', 'User', $session->target_user_id, null, ['session_id' => $session->id]);
        }

        $token->delete();

        return response()->json(['ok' => true]);
    }
}
