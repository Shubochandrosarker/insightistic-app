<?php

namespace App\Services;

use App\Models\AppSumoLicense;
use App\Models\License;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AppSumoLicenseService
{
    /**
     * Fail closed: an unknown paid tier must never silently receive Tier 1.
     */
    public function tierToPlanSlug(?int $tier): string
    {
        // Keep the event-to-plan contract explicit instead of relying on
        // associative-array ordering. This makes a catalog reordering safe
        // and gives the five-tier listing one auditable mapping.
        $slugs = config('commercial.appsumo_tier_plan_slugs', []);
        if (! is_array($slugs) || $slugs === []) {
            $slugs = array_values(array_keys((array) config('commercial.appsumo_plans', [])));
        }

        if ($tier === null || $tier < 1 || $tier > count($slugs)) {
            throw new InvalidArgumentException('Unknown AppSumo license tier.');
        }

        return (string) ($slugs[$tier] ?? $slugs[$tier - 1] ?? throw new InvalidArgumentException('Unknown AppSumo license tier.'));
    }

    /**
     * AppSumo sends "inactive" with a legitimate activate/upgrade/downgrade
     * webhook, and only marks it active AFTER receiving our 200 response.
     * A mere purchase or a deactivation is not enough to claim an account.
     */
    public function isClaimable(AppSumoLicense $row): bool
    {
        return in_array($row->event, ['activate', 'upgrade', 'downgrade'], true)
            && in_array($row->license_status, ['inactive', 'active'], true)
            && ! $row->is_test
            && $row->parent_license_key === null
            && ! AppSumoLicense::query()
                ->where('prev_license_key', $row->license_key)
                ->whereIn('license_status', ['inactive', 'active'])
                ->exists();
    }

    /**
     * Save a signed AppSumo event and project its entitlement atomically.
     * Upgrades replace the key; the existing user's identity and local
     * license MUST move to the replacement before the old key is revoked.
     */
    public function upsertFromPayload(array $payload): AppSumoLicense
    {
        $event = (string) ($payload['event'] ?? '');
        $key = trim((string) ($payload['license_key'] ?? ''));

        if (! in_array($event, ['purchase', 'activate', 'upgrade', 'downgrade', 'deactivate', 'migrate'], true)) {
            throw new InvalidArgumentException('Unknown AppSumo event.');
        }

        if (! Str::isUuid($key) || strlen($key) > 64) {
            throw new InvalidArgumentException('Invalid AppSumo license key.');
        }

        // Deactivation messages may omit tier: retain the last known one.
        // If the key was never seen, record the revoke without inventing a
        // paid tier. Provided but invalid tiers still fail closed.
        $providedTier = array_key_exists('tier', $payload) && $payload['tier'] !== null;
        $incomingTier = $providedTier
            ? filter_var($payload['tier'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        if ($incomingTier === false) {
            throw new InvalidArgumentException('Invalid AppSumo license tier.');
        }

        $isReplacement = in_array($event, ['upgrade', 'downgrade'], true);
        $previousKey = $isReplacement ? trim((string) ($payload['prev_license_key'] ?? '')) : null;

        if ($isReplacement && (! Str::isUuid($previousKey) || $previousKey === $key)) {
            throw new InvalidArgumentException('Replacement AppSumo license is missing a valid previous key.');
        }

        return DB::transaction(function () use ($payload, $event, $key, $incomingTier, $isReplacement, $previousKey) {
            $row = AppSumoLicense::query()->where('license_key', $key)->lockForUpdate()->first()
                ?? new AppSumoLicense(['license_key' => $key]);

            $tier = $incomingTier ?? $row->tier;
            if ($tier === null && ! in_array($event, ['purchase', 'deactivate', 'migrate'], true)) {
                throw new InvalidArgumentException('AppSumo event has no paid tier.');
            }
            $planSlug = $tier !== null ? $this->tierToPlanSlug((int) $tier) : null;

            // AppSumo may retry a purchase after activation, upgrade or
            // deactivation. Never allow a placeholder to undo later events.
            if ($row->exists
                && in_array($row->event, ['activate', 'upgrade', 'downgrade', 'deactivate'], true)
                && $event === 'purchase') {
                return $row;
            }

            $previous = $isReplacement
                ? AppSumoLicense::query()->where('license_key', $previousKey)->lockForUpdate()->first()
                : null;

            if ($previous && $previous->org_id) {
                // Never redirect a previously claimed license to another user.
                if ($row->org_id && $row->org_id !== $previous->org_id) {
                    throw new DomainException('Replacement license belongs to a different organization.');
                }
                if ($row->user_id && $row->user_id !== $previous->user_id) {
                    throw new DomainException('Replacement license belongs to a different user.');
                }
                if ($row->license_id && $row->license_id !== $previous->license_id) {
                    throw new DomainException('Replacement license has a conflicting entitlement.');
                }

                $row->org_id = $previous->org_id;
                $row->user_id = $previous->user_id;
                $row->license_id = $previous->license_id;
            }

            $row->fill([
                'license_key'        => $key,
                'event'              => $event,
                'license_status'     => (string) ($payload['license_status'] ?? 'inactive'),
                'tier'               => $tier,
                'prev_license_key'   => $previousKey ?? $row->prev_license_key,
                'parent_license_key' => $payload['parent_license_key'] ?? null,
                'partner_plan_name'  => $payload['partner_plan_name'] ?? null,
                'unit_quantity'      => isset($payload['unit_quantity']) ? (int) $payload['unit_quantity'] : null,
                'plan_slug'          => $planSlug,
                'raw'                => $payload,
                'event_timestamp'    => (int) ($payload['event_timestamp'] ?? 0),
                'is_test'            => (bool) ($payload['test'] ?? false),
            ]);
            $row->save();

            if ($previous) {
                $previous->license_status = 'deactivated';
                $previous->event = 'deactivate';
                $reason = $payload['extra']['reason'] ?? null;
                if (is_string($reason) && $reason !== '') {
                    $previous->raw = array_merge((array) $previous->raw, ['deactivation_reason' => $reason]);
                }
                $previous->save();
            }

            // Add-ons are NOT sold by this deal. Persist their history, but
            // never project an add-on as a whole paid AppSumo site plan.
            if ($row->parent_license_key !== null) {
                Log::warning('AppSumo add-on event stored without entitlement projection', [
                    'event' => $event,
                    'license_id' => $row->id,
                ]);
            } elseif ($row->org_id) {
                $this->applyEntitlements($row);
            }

            if ($previous && $previous->license_id && $previous->license_id !== $row->license_id) {
                $this->applyEntitlements($previous);
            }

            return $row;
        }, 3);
    }

    /**
     * Link only to an organization the buyer owns. Guard this again inside a
     * DB lock so simultaneous claim submissions cannot transfer a key.
     */
    public function linkToOrganization(string $licenseKey, User $user): AppSumoLicense
    {
        return DB::transaction(function () use ($licenseKey, $user) {
            $row = AppSumoLicense::query()->where('license_key', $licenseKey)->lockForUpdate()->firstOrFail();
            $org = $this->primaryOrganization($user);

            if (! $org || ! $this->isClaimable($row)) {
                throw new DomainException('This AppSumo license is not eligible for activation.');
            }
            if (($row->user_id !== null && $row->user_id !== $user->id)
                || ($row->org_id !== null && $row->org_id !== $org->id)) {
                throw new DomainException('This AppSumo license has already been linked to another account.');
            }

            $row->user_id = $user->id;
            $row->org_id = $org->id;
            $row->save();

            $this->applyEntitlements($row);

            return $row->refresh();
        }, 3);
    }

    public function primaryOrganization(User $user): ?Organization
    {
        // A team member may belong to other customers' organizations; they
        // must never silently apply a personal LTD purchase to those orgs.
        return Organization::query()->where('owner_user_id', $user->id)->orderBy('id')->first();
    }

    /**
     * A replacement is the active source of truth for a reused local license.
     * A late "deactivate" notification for the OLD key must not turn off the
     * new key's plan. Likewise an old activation must not downgrade it.
     */
    public function applyEntitlements(AppSumoLicense $row): void
    {
        if ($row->parent_license_key !== null) {
            return;
        }

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
            Log::warning('AppSumo license has no linked local license', [
                'appsumo_license_id' => $row->id,
                'org_id' => $row->org_id,
            ]);
            return;
        }

        if ($row->org_id && $license->organization_id !== $row->org_id) {
            throw new DomainException('AppSumo and local license organizations do not match.');
        }

        // Only the currently active replacement key can mutate a reused
        // org-level license, including during out-of-order retries.
        $hasActiveReplacement = AppSumoLicense::query()
            ->where('license_id', $license->id)
            ->where('id', '>', $row->id)
            ->whereIn('event', ['activate', 'upgrade', 'downgrade'])
            ->whereIn('license_status', ['inactive', 'active'])
            ->exists();

        if ($hasActiveReplacement) {
            return;
        }

        $deactivated = $row->event === 'deactivate' || $row->license_status === 'deactivated';
        if ($deactivated) {
            $hasOtherActiveKey = AppSumoLicense::query()
                ->where('license_id', $license->id)
                ->where('id', '!=', $row->id)
                ->whereIn('event', ['activate', 'upgrade', 'downgrade'])
                ->whereIn('license_status', ['inactive', 'active'])
                ->exists();

            if ($hasOtherActiveKey) {
                return;
            }
        }

        if ($row->plan_slug) {
            $plan = Plan::query()->where('slug', $row->plan_slug)->first();
            if (! $plan) {
                throw new DomainException('AppSumo tier plan is not seeded.');
            }
            $license->plan_id = $plan->id;
        }

        $license->status = $deactivated ? 'deactivated' : 'active';
        $license->save();

        if ($row->org_id && ! $deactivated) {
            Organization::query()->whereKey($row->org_id)->update([
                'plan_id' => $license->plan_id,
            ]);
            Organization::query()->whereKey($row->org_id)->whereNull('billing_provider')
                ->update(['billing_provider' => 'license']);
        }
    }
}
