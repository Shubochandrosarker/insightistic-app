<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\SyncLog;
use Illuminate\Http\Request;

class SyncLogController extends AdminController
{
    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = SyncLog::query()
            ->with('site:id,name,domain,organization_id')
            ->select(['id', 'site_id', 'job', 'status', 'records', 'message', 'started_at', 'finished_at', 'created_at']);

        if ($siteId = $request->query('site_id')) {
            $query->where('site_id', $siteId);
        }
        if ($orgId = $request->query('organization_id')) {
            $query->whereHas('site', fn ($s) => $s->withoutGlobalScope('organization')->where('organization_id', $orgId));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($job = $request->query('job')) {
            $query->where('job', $job);
        }
        if ($s = $request->query('search')) {
            $query->where('message', $like, "%$s%");
        }

        $this->applySort($query, $request, ['created_at', 'status'], 'created_at');

        return response()->json($query->paginate($this->perPage($request)));
    }
}
