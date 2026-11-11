<?php

namespace App\Services;

use App\Models\AppSumoLicense;
use App\Models\License;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Shared AppSumo Licensing API v2 intake logic (partner guide). Used by both
 * the webhook controller and the OAuth callback controller so every path —
 * purchase, tier change, claim — upserts the appsumo_licenses row the same way.
 *
 * Partner-guide invariants encoded here:
 *  - license_key is the only stable identifier; upsert on it, keep history in raw.
 *  - upgrade/downgrade always issue a NEW key; prev_license_key names the old
 *    one, which AppSumo deactivates simultaneously (we don't wait for that
 *    webhook — we mark the prev row ourselves).
 *  - tier maps to a plan slug by commercial.appsumo_plans declaration order
 *    (1→appsumo_solo, 2→appsumo_studio, 3→appsumo_agency; anything unknown
 *    falls back to the first tier).
 */
class AppSumoLicenseService
{
    /** Tier number → plan slug via config order; unknown tier → appsumo_solo. */
    public function tierToPlanSlug(?int $tier): string
    {
        $slugs = array_keys((array) config('commercial.appsumo_plans', []));

        if ($tier === null || $tier < 1 || $tier > count($slugs)) {
            return 'appsumo_solo';
        }

        return $slugs[$tier - 1];
    }

    /**
     * Upsert one webhook/OAuth payload keyed by license_key, then apply
     * entitlements when the row is already claimed by an org. Returns null
     * when the payload carries no license_key (nothing to key the row on).
     */
    public function upsertFromPayload(array $payload): ?AppSumoLicense
    {
        $event = (string) ($payload['event'] ?? 'unknown');
        $tier = isset($payload['tier']) ? (int) $payload['tier'] : null;
        $licenseKey = (string) ($payload['license_key'] ?? '');

        if ($licenseKey === '') {
            Log::warning('AppSumo payload without license_key ignored', ['event' => $event]);

            return null;
        }

        $row = AppSumoLicense::query()->firstOrNew(['license_key' => $licenseKey]);

        $row->fill([
            'event'              => $event,
            'license_status'     => (string) ($payload['license_status'] ?? 'inactive'),
            'tier'               => $tier,
            'parent_license_key' => $payload['parent_license_key'] ?? null,
            'partner_plan_name'  => $payload['partner_plan_name'] ?? null,
            'unit_quantity'      => isset($payload['unit_quantity']) ? (int) $payload['unit_quantity'] : null,
            'plan_slug'          => $this->tierToPlanSlug($tier),
            'raw'                => $payload,
            'event_timestamp'    => (int) ($payload['event_timestamp'] ?? 0),
            'is_test'            => (bool) ($payload['test'] ?? false),
        ]);

        if (! empty($payload['prev_license_key'])) {
            $row->prev_license_key = (string) $payload['prev_license_key'];

            if (in_array($event, ['upgrade', 'downgrade'], true)) {
                $this->deactivatePrevious($row->prev_license_key, $payload['extra']['reason'] ?? null);
            }
        }

        $row->save();

        if ($row->license_id || $row->org_id) {
            $this->applyEntitlements($row);
        }

        return $row;
    }

    /**
     * Bind an AppSumo license to a user's organization (OAuth claim flow).
     * Resolves the user's primary org — owned first, then first membership —
     * and applies entitlements. Returns the row so callers can react (the
     * raw Insightistic license key, when one must be issued for the plugin
     * handshake, is the claim endpoint's concern: it is shown once and never
     * stored).
     */
    public function linkToOrganization(string $licenseKey, User $user): AppSumoLicense
    {
        $row = AppSumoLicense::query()->where('license_key', $licenseKey)->firstOrFail();

        $org = Organization::query()->where('owner_user_id', $user->id)->orderBy('id')->first()
            ?? Organization::query()
                ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
                ->orderBy('id')
                ->first();

        $row->user_id = $user->id;
        $row->org_id = $org?->id;
        $row->save();

        if ($org) {
            $this->applyEntitlements($row);
        }

        return $row;
    }

    /**
     * Project an AppSumo row's tier/status onto the linked org-level
     * `licenses` row (the one the plugin validates against): plan from the
     * tier mapping, status active/deactivated. `deactivated` sits outside
     * License::STATUSES on purpose — License::isEntitled() treats anything
     * not in ENTITLED_STATUSES as unentitled, which is the effect we want.
     *
     * The org-level billing_provider is set to the generic 'license' value
     * (never 'appsumo') only when empty, so it stays inside the allowed
     * provider set and never clobbers a real Stripe/Paddle attribution.
     */
    public function applyEntitlements(AppSumoLicense $row): void
    {
        $license = $row->license;

        if (! $license && $row->org_id) {
            $license = License::withoutGlobalScope('organization')
                ->where('organization_id', $row->org_id)
                ->orderBy('id')
                ->first();

            if ($license) {
                $row->license_id = $license->id;
                $row->save();
            }
        }

        if (! $license) {
            Log::warning('AppSumo license has no linked licenses row to update', [
                'license_key' => $row->license_key,
                'org_id'      => $row->org_id,
            ]);

            return;
        }

        if ($row->plan_slug) {
            $plan = Plan::query()->where('slug', $row->plan_slug)->first();
            if ($plan) {
                $license->plan_id = $plan->id;
            }
        }

        // Guide nuance: a deactivate event arrives with license_status still
        // "active" (AppSumo flips it only after our 200 ack) — the event type
        // decides, not just the payload status. 'inactive' (valid, not yet
        // activated) keeps the local license usable: the purchase is real.
        $deactivated = $row->event === 'deactivate' || $row->license_status === 'deactivated';
        $license->status = $deactivated ? 'deactivated' : 'active';
        $license->save();

        if ($row->org_id) {
            Organization::query()->whereKey($row->org_id)->whereNull('billing_provider')
                ->update(['billing_provider' => 'license']);
        }
    }

    /**
     * Mark the replaced license row deactivated on upgrade/downgrade. The
     * reason goes into raw (the table has no dedicated column) so support can
     * see why the old key died even if AppSumo's own deactivate webhook is
     * still in flight.
     */
    private function deactivatePrevious(string $prevKey, mixed $reason): void
    {
        $prev = AppSumoLicense::query()->where('license_key', $prevKey)->first();

        if (! $prev) {
            // Not seen before — AppSumo's simultaneous deactivate webhook will
            // upsert it with license_status deactivated on its own.
            return;
        }

        $prev->license_status = 'deactivated';
        $reason = is_string($reason) ? $reason : null;
        if ($reason !== null && $reason !== '') {
            $prev->raw = array_merge((array) $prev->raw, ['deactivation_reason' => $reason]);
        }
        $prev->save();

        if ($prev->license_id || $prev->org_id) {
            $this->applyEntitlements($prev);
        }
    }
}
