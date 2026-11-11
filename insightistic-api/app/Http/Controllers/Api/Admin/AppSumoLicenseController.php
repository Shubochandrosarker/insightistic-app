<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AppSumoLicense;
use Illuminate\Http\Request;

/**
 * Super Admin AppSumo license lookup (partner guide: every license_key must
 * be stored and searchable by support — AppSumo keeps no customer emails, so
 * this table is the only place a buyer can be traced from their key).
 */
class AppSumoLicenseController extends AdminController
{
    /** GET /admin/appsumo/licenses?q=<license_key fragment | linked user email> */
    public function index(Request $request)
    {
        $query = AppSumoLicense::query()->with([
            'user:id,name,email',
            'organization:id,name,slug',
            'license:id,organization_id,plan_id,status',
        ]);

        if ($q = trim((string) $request->query('q'))) {
            $like = $this->likeOp();

            $query->where(function ($w) use ($q, $like) {
                $w->where('license_key', $like, "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', $like, "%{$q}%"));
            });
        }

        $this->applySort($query, $request, ['created_at', 'license_status'], 'created_at');

        return response()->json($query->paginate($this->perPage($request)));
    }
}
