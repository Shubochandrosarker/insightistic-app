<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Single write path for admin_audit_logs (spec §13.1). Every Super Admin
 * mutation should call this — before/after are plain arrays the caller
 * builds from already-safe-to-log fields; this never redacts anything
 * itself, so callers must not pass secrets (see Admin\LicenseController,
 * which never has a raw license key in scope to log by construction).
 */
class AuditLogger
{
    public function log(
        Request $request,
        string $action,
        string $targetType,
        ?int $targetId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): AdminAuditLog {
        return AdminAuditLog::create([
            'actor_user_id'   => $request->user()?->id,
            'action'          => $action,
            'target_type'     => $targetType,
            'target_id'       => $targetId,
            'before_json'     => $before,
            'after_json'      => $after,
            'reason'          => $reason,
            'ip_address'      => $request->ip(),
            'user_agent'      => substr((string) $request->userAgent(), 0, 500),
            'correlation_id'  => (string) Str::uuid(),
            'created_at'      => now(),
        ]);
    }
}
