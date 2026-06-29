<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsageController extends AdminController
{
    /** Per-organization usage vs plan limits + a platform monthly trend. */
    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = Organization::query()
            ->with(['plan:id,name,slug,ai_insight_limit,report_limit,site_limit,user_limit', 'currentUsage'])
            ->withCount(['sites', 'users']);

        if ($s = $request->query('search')) {
            $query->where('name', $like, "%$s%");
        }
        if ($plan = $request->query('plan')) {
            $query->whereHas('plan', fn ($p) => $p->where('slug', $plan));
        }

        $this->applySort($query, $request, ['created_at', 'name'], 'created_at');

        $trend = DB::table('usage_counters')
            ->select('period', DB::raw('SUM(ai_insights_used) as ai_insights'), DB::raw('SUM(reports_generated) as reports'))
            ->groupBy('period')
            ->orderBy('period', 'desc')
            ->limit(6)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'organizations' => $query->paginate($this->perPage($request)),
            'trend'         => $trend,
        ]);
    }
}
