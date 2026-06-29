<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ClientReport;
use Illuminate\Http\Request;

class ReportController extends AdminController
{
    public function index(Request $request)
    {
        $query = ClientReport::withoutGlobalScope('organization')
            ->with(['site:id,name,domain', 'organization:id,name,slug']);

        if ($orgId = $request->query('organization_id')) {
            $query->where('organization_id', $orgId);
        }
        if ($siteId = $request->query('site_id')) {
            $query->where('site_id', $siteId);
        }
        if ($type = $request->query('report_type')) {
            $query->where('report_type', $type);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $this->applySort($query, $request, ['created_at', 'report_type'], 'created_at');

        return response()->json($query->paginate($this->perPage($request)));
    }
}
