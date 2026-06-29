<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AiInsight;
use App\Models\ClientReport;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SyncLog;
use Illuminate\Http\Request;

class OrganizationController extends AdminController
{
    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = Organization::query()
            ->with(['plan:id,name,slug', 'owner:id,name,email'])
            ->withCount(['sites', 'users']);

        if ($s = $request->query('search')) {
            $query->where(function ($w) use ($s, $like) {
                $w->where('name', $like, "%$s%")
                    ->orWhere('slug', $like, "%$s%")
                    ->orWhereHas('owner', fn ($o) => $o->where('email', $like, "%$s%"));
            });
        }
        if ($plan = $request->query('plan')) {
            $query->whereHas('plan', fn ($p) => $p->where('slug', $plan));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $this->applySort($query, $request, ['created_at', 'name', 'status'], 'created_at');

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function show(int $id)
    {
        $org = Organization::with(['plan', 'owner:id,name,email,last_login_at', 'subscription'])
            ->withCount(['sites', 'users'])
            ->findOrFail($id);

        $sites = Site::withoutGlobalScope('organization')->where('organization_id', $id)
            ->get(['id', 'name', 'domain', 'connection_status', 'last_sync_at']);

        return response()->json([
            'organization' => $org,
            'owner'        => $org->owner,
            'plan'         => $org->plan,
            'subscription' => $org->subscription,
            'sites'        => $sites,
            'members'      => $org->users()->get(['users.id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role]),
            'usage'        => $org->currentUsage,
            'recent_sync_logs' => SyncLog::whereIn('site_id', $sites->pluck('id'))
                ->latest('id')->limit(10)->get(['id', 'site_id', 'job', 'status', 'records', 'created_at']),
            'recent_reports' => ClientReport::withoutGlobalScope('organization')->where('organization_id', $id)
                ->latest('id')->limit(10)->get(['id', 'site_id', 'title', 'report_type', 'created_at']),
            'ai_insights_count' => AiInsight::withoutGlobalScope('organization')->where('organization_id', $id)->count(),
        ]);
    }

    /** Manually change plan and/or suspend/reactivate. */
    public function update(Request $request, int $id)
    {
        $org = Organization::findOrFail($id);

        $data = $request->validate([
            'plan'   => ['nullable', 'string', 'exists:plans,slug'],
            'status' => ['nullable', 'in:trialing,active,past_due,suspended,canceled'],
        ]);

        if (! empty($data['plan'])) {
            $org->plan_id = Plan::where('slug', $data['plan'])->value('id');
        }
        if (! empty($data['status'])) {
            $org->status = $data['status'];
        }
        $org->save();

        return response()->json(['organization' => $org->fresh(['plan'])]);
    }
}
