<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ManagedLicense;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ManagedLicenseController extends AdminController
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $like = $this->likeOp();
        $query = ManagedLicense::withoutGlobalScope('organization')
            ->with(['organization:id,name,slug', 'user:id,name,email', 'site:id,name,domain']);

        if (! $request->boolean('include_archived')) $query->whereNull('archived_at');
        if ($s = $request->query('search')) {
            $query->where(function ($w) use ($s, $like) {
                $w->where('product_name', $like, "%$s%")
                    ->orWhere('provider', $like, "%$s%")
                    ->orWhere('license_key_last4', $like, "%$s%")
                    ->orWhereHas('organization', fn ($o) => $o->where('name', $like, "%$s%"))
                    ->orWhereHas('site', fn ($site) => $site->where('domain', $like, "%$s%"));
            });
        }
        if ($status = $request->query('status')) $query->where('status', $status);

        $this->applySort($query, $request, ['created_at', 'product_name', 'expires_at', 'status'], 'created_at');
        $page = $query->paginate($this->perPage($request));
        $page->getCollection()->transform(fn (ManagedLicense $license) => $license->summary());

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'product_name' => ['required', 'string', 'max:150'],
            'license_type' => ['required', 'string', 'max:50'],
            'provider' => ['nullable', 'string', 'max:100'],
            'plan_name' => ['nullable', 'string', 'max:120'],
            'license_key' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:active,trialing,expired,suspended,canceled'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $org = Organization::findOrFail($data['organization_id']);
        if (! empty($data['user_id']) && ! $org->users()->where('users.id', $data['user_id'])->exists()) {
            return response()->json(['message' => 'The selected user is not a member of this organization.'], 422);
        }
        if (! empty($data['site_id']) && ! Site::withoutGlobalScope('organization')->where('organization_id', $org->id)->whereKey($data['site_id'])->exists()) {
            return response()->json(['message' => 'The selected site does not belong to this organization.'], 422);
        }

        $key = $data['license_key'] ?? null;
        $license = ManagedLicense::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'user_id' => $data['user_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'product_name' => $data['product_name'],
            'license_type' => $data['license_type'],
            'provider' => $data['provider'] ?? null,
            'plan_name' => $data['plan_name'] ?? null,
            'license_key_encrypted' => $key,
            'license_key_last4' => $key ? substr($key, -4) : null,
            'status' => $data['status'],
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->log($request, 'managed_license.create', 'ManagedLicense', $license->id, null, [
            'organization_id' => $license->organization_id,
            'site_id' => $license->site_id,
            'product_name' => $license->product_name,
            'license_type' => $license->license_type,
        ]);

        return response()->json(['managed_license' => $license->fresh(['organization', 'user', 'site'])->summary()], 201);
    }

    public function update(Request $request, int $id)
    {
        $license = ManagedLicense::withoutGlobalScope('organization')->findOrFail($id);
        $data = $request->validate([
            'status' => ['sometimes', 'in:active,trialing,expired,suspended,canceled'],
            'plan_name' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $before = $license->only(['status', 'plan_name', 'expires_at', 'notes']);
        $license->update($data);
        $this->audit->log($request, 'managed_license.update', 'ManagedLicense', $license->id, $before, $license->fresh()->only(['status', 'plan_name', 'expires_at', 'notes']));

        return response()->json(['managed_license' => $license->fresh(['organization', 'user', 'site'])->summary()]);
    }
}
