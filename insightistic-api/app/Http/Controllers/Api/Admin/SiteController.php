<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\LicenseActivation;
use App\Models\Site;
use App\Models\SyncLog;
use App\Models\WcCustomer;
use App\Models\WcOrder;
use App\Models\WcProduct;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SiteController extends AdminController
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = Site::withoutGlobalScope('organization')
            ->with('organization:id,name,slug')
            ->select(['id', 'organization_id', 'name', 'domain', 'platform', 'connection_status', 'last_sync_at', 'wc_version', 'plugin_version', 'archived_at', 'created_at']);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }
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

    /**
     * POST /admin/sites/{id}/disconnect — revokes connector credentials and
     * deactivates any active license activation tied to this site, without
     * archiving it. The site row and its historical order/product/customer
     * data stay exactly as they are; only its ability to sync again does not,
     * until it's reconnected from the plugin/dashboard.
     */
    public function disconnect(Request $request, int $id)
    {
        $site = Site::withoutGlobalScope('organization')->findOrFail($id);

        $site->connector_key_id = null;
        $site->connector_secret = null;
        $site->connection_status = 'disconnected';
        $site->save();

        LicenseActivation::where('site_id', $site->id)->where('status', 'active')
            ->update(['status' => 'deactivated', 'deactivated_at' => now()]);

        $this->audit->log($request, 'site.disconnect', 'Site', $site->id, ['connection_status' => 'connected'], ['connection_status' => 'disconnected']);

        return response()->json(['site' => $site->fresh(['organization'])]);
    }

    /** POST /admin/sites/{id}/archive */
    public function archive(Request $request, int $id)
    {
        $site = Site::withoutGlobalScope('organization')->findOrFail($id);
        $site->archived_at = now();
        $site->save();

        $this->audit->log($request, 'site.archive', 'Site', $site->id, ['archived_at' => null], ['archived_at' => $site->archived_at->toIso8601String()]);

        return response()->json(['site' => $site->only(['id', 'archived_at'])]);
    }

    /** POST /admin/sites/{id}/restore */
    public function restore(Request $request, int $id)
    {
        $site = Site::withoutGlobalScope('organization')->findOrFail($id);
        $site->archived_at = null;
        $site->save();

        $this->audit->log($request, 'site.restore', 'Site', $site->id, null, ['archived_at' => null]);

        return response()->json(['site' => $site->only(['id', 'archived_at'])]);
    }
}
