<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Organization;
use App\Models\PluginEvent;
use App\Services\AuditLogger;
use App\Services\LicenseService;
use Illuminate\Http\Request;

/**
 * Super Admin license/activation operations (master prompt §13.6). Every
 * mutation here is deliberately routed through LicenseService rather than
 * writing License columns directly, so the precedence rules in
 * LicenseService::computeStatus() stay the single source of truth for what
 * a given status actually means and how it can (or can't) be undone.
 *
 * Route-model binding is intentionally NOT used for License — admin routes
 * omit the `tenant` middleware so BelongsToOrganization's scope is already
 * inert here, but every lookup still resolves explicitly with
 * withoutGlobalScope('organization') to match the rest of this codebase's
 * convention for admin/unscoped queries (see BelongsToOrganization's own
 * doc comment).
 */
class LicenseController extends AdminController
{
    public function __construct(
        private LicenseService $licenses,
        private AuditLogger $audit,
    ) {}

    /** GET /admin/licenses — search by org name/slug, last four, status, plan, or activation domain. */
    public function index(Request $request)
    {
        $like = $this->likeOp();

        $query = License::withoutGlobalScope('organization')
            ->with(['organization:id,name,slug', 'plan:id,name,slug']);

        if ($s = $request->query('search')) {
            $query->where(function ($w) use ($s, $like) {
                $w->where('license_key_last4', $like, "%$s%")
                    ->orWhereHas('organization', fn ($o) => $o->where('name', $like, "%$s%")->orWhere('slug', $like, "%$s%"))
                    ->orWhereHas('activations', fn ($a) => $a->where('normalized_site_url', $like, "%$s%"));
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($plan = $request->query('plan')) {
            $query->whereHas('plan', fn ($p) => $p->where('slug', $plan));
        }

        $this->applySort($query, $request, ['created_at', 'status'], 'created_at');

        $page = $query->paginate($this->perPage($request));
        $page->getCollection()->transform(fn (License $l) => $this->summarize($l));

        return response()->json($page);
    }

    /** GET /admin/licenses/{id} — full detail: activations, event history, entitlement preview. */
    public function show(int $id)
    {
        $license = $this->find($id);

        return response()->json([
            'license'      => $this->summarize($license),
            'entitlements' => $this->licenses->entitlements($license),
            'activations'  => $license->activations()->with('site:id,name,domain,connection_status')
                ->orderByDesc('activated_at')->get(),
            'events' => PluginEvent::where('license_id', $license->id)
                ->latest('id')->limit(50)->get(['id', 'event_type', 'payload_json', 'created_at']),
        ]);
    }

    /** POST /admin/licenses — issue a license for an org that doesn't have one. */
    public function store(Request $request)
    {
        $data = $request->validate(['organization_id' => ['required', 'integer', 'exists:organizations,id']]);

        $org = Organization::findOrFail($data['organization_id']);

        if (License::withoutGlobalScope('organization')->where('organization_id', $org->id)->exists()) {
            return response()->json(['message' => 'This organization already has a license.'], 422);
        }

        $raw = $this->licenses->issue($org);
        $license = License::withoutGlobalScope('organization')->where('organization_id', $org->id)->latest('id')->first();

        $this->audit->log($request, 'license.issue', 'License', $license->id, null, ['organization_id' => $org->id]);

        return response()->json([
            'license'     => $this->summarize($license),
            'license_key' => $raw,
            'notice'      => 'Copy this key now — it will not be shown again.',
        ], 201);
    }

    /** POST /admin/licenses/{id}/regenerate */
    public function regenerate(Request $request, int $id)
    {
        $license = $this->find($id);
        $raw = $this->licenses->regenerate($license);

        $this->audit->log($request, 'license.regenerate', 'License', $license->id);

        return response()->json([
            'license_key' => $raw,
            'last4'       => substr($raw, -4),
            'notice'      => 'Copy this key now — it will not be shown again. The old key has stopped working.',
        ]);
    }

    /** POST /admin/licenses/{id}/suspend */
    public function suspend(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $license = $this->find($id);
        $before = $license->only(['status']);
        $this->licenses->suspend($license, $data['reason'] ?? null);

        $this->audit->log($request, 'license.suspend', 'License', $license->id, $before, ['status' => 'suspended'], $data['reason'] ?? null);

        return response()->json(['license' => $this->summarize($license->fresh())]);
    }

    /** POST /admin/licenses/{id}/reactivate */
    public function reactivate(Request $request, int $id)
    {
        $license = $this->find($id);
        $before = $license->only(['status']);
        $this->licenses->reactivate($license);

        $this->audit->log($request, 'license.reactivate', 'License', $license->id, $before, $license->fresh()->only(['status']));

        return response()->json(['license' => $this->summarize($license->fresh())]);
    }

    /** POST /admin/licenses/{id}/revoke — permanent; cannot be undone by reactivate(). */
    public function revoke(Request $request, int $id)
    {
        $data = $request->validate([
            'confirm' => ['required', 'accepted'],
            'reason'  => ['nullable', 'string', 'max:500'],
        ]);

        $license = $this->find($id);
        $before = $license->only(['status']);
        $this->licenses->revoke($license, $data['reason'] ?? null);

        $this->audit->log($request, 'license.revoke', 'License', $license->id, $before, ['status' => 'revoked'], $data['reason'] ?? null);

        return response()->json(['license' => $this->summarize($license->fresh())]);
    }

    /** POST /admin/licenses/{id}/disconnect-all — deactivates every connected site at once. */
    public function disconnectAll(Request $request, int $id)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        $license = $this->find($id);
        $count = $this->licenses->disconnectAll($license);

        $this->audit->log($request, 'license.disconnect_all', 'License', $license->id, null, ['disconnected' => $count]);

        return response()->json(['ok' => true, 'disconnected' => $count]);
    }

    /** PATCH /admin/licenses/{id}/activation-limit */
    public function overrideActivationLimit(Request $request, int $id)
    {
        $data = $request->validate([
            'limit'  => ['required', 'integer', 'min:0', 'max:10000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $license = $this->find($id);
        $before = $license->only(['activation_limit']);
        $this->licenses->overrideActivationLimit($license, $data['limit'], $data['reason'] ?? null);

        $this->audit->log($request, 'license.activation_limit_override', 'License', $license->id, $before, ['activation_limit' => $data['limit']], $data['reason'] ?? null);

        return response()->json(['license' => $this->summarize($license->fresh())]);
    }

    /** DELETE /admin/activations/{id} — revoke a single site's activation. */
    public function revokeActivation(Request $request, int $id)
    {
        // LicenseActivation has no organization_id / BelongsToOrganization scope
        // of its own — tenant isolation for it flows through its License.
        $activation = LicenseActivation::findOrFail($id);
        $activation->update(['status' => 'deactivated', 'deactivated_at' => now()]);
        $activation->site?->update(['connection_status' => 'disconnected']);

        PluginEvent::record($activation->license, $activation, 'activation_revoked_by_admin');
        $this->audit->log($request, 'license.activation_revoke', 'LicenseActivation', $activation->id, ['status' => 'active'], ['status' => 'deactivated']);

        return response()->json(['ok' => true]);
    }

    private function find(int $id): License
    {
        return License::withoutGlobalScope('organization')->findOrFail($id);
    }

    private function summarize(License $license): array
    {
        return [
            'id'                => $license->id,
            'organization'      => $license->organization?->only(['id', 'name', 'slug']),
            'masked_key'        => $license->maskedKey(),
            'last4'             => $license->license_key_last4,
            'status'            => $license->status,
            'plan'              => $license->plan?->only(['id', 'slug', 'name']),
            'activation_limit'  => (int) $license->activation_limit,
            'activations_used'  => $license->countedActivations(),
            'is_entitled'       => $license->isEntitled(),
            'created_at'        => $license->created_at?->toIso8601String(),
            'last_validated_at' => $license->last_validated_at?->toIso8601String(),
            'revoked_at'        => $license->revoked_at?->toIso8601String(),
        ];
    }
}
