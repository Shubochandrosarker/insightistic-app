<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionController extends AdminController
{
    /** Stripe ids are safe to show; secret keys live only in config and are never returned. */
    public function index(Request $request)
    {
        $query = Subscription::withoutGlobalScope('organization')
            ->with(['organization:id,name,slug', 'plan:id,name,slug,price_monthly']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($orgId = $request->query('organization_id')) {
            $query->where('organization_id', $orgId);
        }
        if ($request->filled('canceled')) {
            $query->where('cancel_at_period_end', $request->boolean('canceled'));
        }

        $this->applySort($query, $request, ['created_at', 'current_period_end', 'status'], 'created_at');

        return response()->json($query->paginate($this->perPage($request)));
    }
}
