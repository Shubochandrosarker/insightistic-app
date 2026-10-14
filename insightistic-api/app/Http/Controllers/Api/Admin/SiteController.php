<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\LicenseActivation;
use App\Models\Site;
use App\Models\SyncLog;
use App\Models\WcCustomer;
use App\Models\WcOrder;
use App\Models\WcProduct;
use App\Services\AuditLogger;
use App\Support\DomainNormalizer;
use Illuminate\Http\Request;

class SiteController extends AdminController
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = Site::withoutGlobalScope('organization')
            ->with('organization:id,name,slug')
            ->select(['id', 'organization_id', 'name', 'domain', 'platform', 'platform_override', 'country', 'language', 'timezone', 'currency', 'status', 'connection_status', 'last_sync_at', 'wc_version', 'plugin_version', 'archived_at', 'created_at']);

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

    /** POST /admin/sites — register a site for a tenant without requiring a plugin handshake. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'name'            => ['nullable', 'string', 'max:120'],
            'domain'          => ['required', 'string', 'max:190'],
            'platform'        => ['nullable', 'string', 'max:32'],
            'country'         => ['nullable', 'string', 'size:2'],
            'language'        => ['nullable', 'string', 'max:12'],
        ]);

        try {
            $parts = DomainNormalizer::parse($data['domain']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_domain'], 422);
        }

        $existing = Site::withoutGlobalScope('organization')
            ->where('organization_id', $data['organization_id'])
            ->where('normalized_domain', $parts['host_key'])
            ->whereNull('archived_at')
            ->first();
        if ($existing) {
            return response()->json(['message' => 'This website is already registered for the organization.', 'code' => 'duplicate_site'], 409);
        }

        $site = Site::withoutGlobalScope('organization')->create([
            'organization_id' => $data['organization_id'],
            'name' => $data['name'] ?? $parts['host'],
            'domain' => $parts['host'],
            'normalized_domain' => $parts['host_key'],
            'canonical_url' => $parts['canonical'],
            'platform' => $data['platform'] ?? 'generic',
            'country' => strtoupper($data['country'] ?? '') ?: null,
            'language' => $data['language'] ?? null,
            'status' => 'active',
            'connection_status' => 'not_installed',
        ]);

        $this->audit->log($request, 'site.create', 'Site', $site->id, null, ['organization_id' => $site->organization_id, 'domain' => $site->domain, 'platform' => $site->platform]);

        return response()->json(['site' => $site->fresh(['organization']), 'notice' => 'Site registered. The connector remains disconnected until the site owner connects it.'], 201);
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
     * PATCH /admin/sites/{id} — rename / activate-suspend a site from the
     * platform-owner console. Domain is deliberately NOT editable here: it's
     * the dedup + connector identity anchor, and silently rewriting it would
     * detach the site from its synced data. (Archive + re-register covers
     * genuine moves.)
     */
    public function update(Request $request, int $id)
    {
        $site = Site::withoutGlobalScope('organization')->findOrFail($id);
        $before = $site->only(['name', 'platform', 'platform_override', 'country', 'language', 'timezone', 'currency', 'status']);

        $data = $request->validate([
            'name'              => ['sometimes', 'string', 'max:120'],
            'platform'          => ['sometimes', 'string', 'max:32'],
            'platform_override' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country'           => ['sometimes', 'nullable', 'string', 'size:2'],
            'language'          => ['sometimes', 'nullable', 'string', 'max:12'],
            'timezone'          => ['sometimes', 'nullable', 'string', 'max:64'],
            'currency'          => ['sometimes', 'nullable', 'string', 'size:3'],
            'status'            => ['sometimes', 'in:active,suspended'],
        ]);

        foreach (['name', 'platform', 'platform_override', 'language', 'timezone', 'currency'] as $field) {
            if (array_key_exists($field, $data)) {
                $site->{$field} = $field === 'name' ? trim((string) $data[$field]) : ($data[$field] === null ? null : trim((string) $data[$field]));
            }
        }
        if (array_key_exists('country', $data)) {
            $site->country = $data['country'] === null ? null : strtoupper(trim($data['country']));
        }
        if (! empty($data['status'])) {
            $site->status = $data['status'];
        }
        $site->save();

        if ($site->wasChanged()) {
            $this->audit->log($request, 'site.update', 'Site', $site->id, $before, $site->only(['name', 'platform', 'platform_override', 'country', 'language', 'timezone', 'currency', 'status']));
        }

        return response()->json(['site' => $site->only(['id', 'name', 'domain', 'platform', 'platform_override', 'country', 'language', 'timezone', 'currency', 'status', 'connection_status'])]);
    }

    /**
     * POST /admin/sites/{id}/regenerate-key — rotate the site's connector
     * HMAC key pair. Mirrors the tenant-side regenerate: the old pair dies
     * instantly, the new token is shown once, and the site drops back to
     * not_installed until the plugin reconnects with the fresh token.
     */
    public function regenerateKey(Request $request, int $id)
    {
        $site = Site::withoutGlobalScope('organization')->findOrFail($id);

        $token = $site->issueConnectorToken();
        $site->connection_status = 'not_installed';
        $site->save();

        $this->audit->log($request, 'site.connector_key_regenerated', 'Site', $site->id, null, ['connection_status' => 'not_installed']);

        return response()->json([
            'connector_token' => $token,
            'notice'          => 'Old connector token is now invalid. Update the plugin with this new token.',
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
