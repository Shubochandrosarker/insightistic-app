<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Tenancy;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function current(Tenancy $tenancy)
    {
        $org = $tenancy->organization()->load(['plan', 'currentUsage']);

        return response()->json([
            'organization' => $org->only(['id', 'name', 'slug', 'status', 'trial_ends_at']),
            'plan'         => $org->plan,
            'usage'        => $org->currentUsage,
        ]);
    }

    public function update(Request $request, Tenancy $tenancy)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $org = $tenancy->organization();
        $org->update($data);

        return response()->json(['organization' => $org->only(['id', 'name', 'slug'])]);
    }
}
