<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

/** Read-only view of admin_audit_logs (spec §13.1) — the log itself is immutable, no writes here. */
class AuditLogController extends AdminController
{
    public function index(Request $request)
    {
        $query = AdminAuditLog::with('actor:id,name,email')->latest('id');

        if ($type = $request->query('target_type')) {
            $query->where('target_type', $type);
        }
        if ($targetId = $request->query('target_id')) {
            $query->where('target_id', $targetId);
        }
        if ($actorId = $request->query('actor_user_id')) {
            $query->where('actor_user_id', $actorId);
        }
        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        return response()->json($query->paginate($this->perPage($request)));
    }
}
