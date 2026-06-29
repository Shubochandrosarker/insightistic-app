<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Site;
use App\Models\SyncLog;
use App\Models\WcCustomer;
use App\Models\WcOrder;
use App\Models\WcProduct;
use Illuminate\Http\Request;

class SiteController extends AdminController
{
    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = Site::withoutGlobalScope('organization')
            ->with('organization:id,name,slug')
            ->select(['id', 'organization_id', 'name', 'domain', 'platform', 'connection_status', 'last_sync_at', 'wc_version', 'plugin_version', 'created_at']);

        if ($s = $request->query('search')) {
            $query->where(fn ($w) => $w->where('name', $like, "%$s%")->orWhere('domain', $like, "%$s%"));
        }
        if ($orgId = $request->query('organization_id')) {
            $query->where('organization_id', $orgId);
        }
        if ($status = $request->query('connection_status')) {
            $query->where('connection_status', $status);
        }

        $this->applySort($query, $request, ['created_at', 'name', 'last_sync_at'], 'created_at');

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function show(int $id)
    {
        $site = Site::withoutGlobalScope('organization')
            ->with('organization:id,name,slug,plan_id')
            ->findOrFail($id);

        return response()->json([
            'site'   => $site,
            'counts' => [
                'orders'    => WcOrder::where('site_id', $id)->count(),
                'products'  => WcProduct::where('site_id', $id)->count(),
                'customers' => WcCustomer::where('site_id', $id)->count(),
            ],
            'recent_sync_logs' => SyncLog::where('site_id', $id)
                ->latest('id')->limit(15)->get(['id', 'job', 'status', 'records', 'message', 'started_at', 'finished_at']),
            'recent_errors' => SyncLog::where('site_id', $id)->where('status', 'failed')
                ->latest('id')->limit(10)->get(['id', 'job', 'message', 'created_at']),
        ]);
    }
}
