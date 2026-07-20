<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * Short-lived, audited impersonation (spec §13.9). start() is Super-Admin-
 * only (this controller lives under /admin, gated by the super_admin
 * middleware); end() belongs to the impersonated session itself and is
 * wired separately under plain auth:sanctum — see PluginAiController-style
 * routing note in routes/api.php.
 */
class ImpersonationController extends AdminController
{
    public function __construct(private AuditLogger $audit) {}

    /** POST /admin/impersonate */
    public function start(Request $request)
    {
        $data = $request->validate([
            'user_id'  => ['required', 'integer', 'exists:users,id'],
            'reason'   => ['required', 'string', 'max:500'],
            'elevated' => ['sometimes', 'boolean'],
        ]);

        $target = User::findOrFail($data['user_id']);
        $elevated = (bool) ($data['elevated'] ?? false);

        // Impersonating another platform operator is a materially different
        // (and much higher-blast-radius) risk than impersonating a customer
        // — not supported here.
        abort_if($target->is_super_admin, 422, 'Cannot impersonate another Super Admin.');
        abort_if($target->archived_at, 422, 'Cannot impersonate an archived user.');
        abort_if($target->status !== 'active', 422, 'Cannot impersonate a non-active user.');

        $abilities = $elevated ? ['*', 'impersonated', 'impersonated-elevated'] : ['impersonated'];
        $newToken = $target->createToken('impersonation:' . $request->user()->id, $abilities);

        $session = ImpersonationSession::create([
            'admin_user_id'            => $request->user()->id,
            'target_user_id'           => $target->id,
            'personal_access_token_id' => $newToken->accessToken->id,
            'reason'                   => $data['reason'],
            'elevated'                 => $elevated,
            'ip_address'               => $request->ip(),
            'started_at'               => now(),
        ]);

        $this->audit->log(
            $request,
            'impersonation.start',
            'User',
            $target->id,
            null,
            ['session_id' => $session->id, 'elevated' => $elevated],
            $data['reason'],
        );

        return response()->json([
            'token' => $newToken->plainTextToken,
            'user'  => $target->only(['id', 'name', 'email']),
            'impersonation' => [
                'session_id'  => $session->id,
                'admin_name'  => $request->user()->name,
                'target_name' => $target->name,
                'reason'      => $data['reason'],
                'elevated'    => $elevated,
                'started_at'  => $session->started_at->toIso8601String(),
            ],
        ], 201);
    }

    /** GET /admin/impersonations — recent sessions, for the audit-adjacent admin view. */
    public function index(Request $request)
    {
        $query = ImpersonationSession::with(['admin:id,name,email', 'target:id,name,email'])->latest('started_at');

        return response()->json($query->paginate($this->perPage($request)));
    }
}
