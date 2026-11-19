<?php

namespace App\Domain\Billing;

use App\Models\AppSumoLicense;
use App\Models\EntitlementGrant;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

/**
 * Single authority for what an organization is entitled to (roadmap doc 24).
 * Read-only: precedence is active subscription > lifetime grant > manual /
 * promotion grants merged additively > free, with org suspension cutting
 * through everything and an admin override lifting a plan back onto a
 * subscription regardless of its status. UsageService stays the enforcement
 * point — it can move to limitFor()/hasFeature() per key without its
 * callers changing.
 */
final class EntitlementResolver
{
    /** Subscription statuses that keep a plan attached to the org. */
    private const ACTIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing', 'past_due', 'paused'];

    /** Grants apply in this order; later sources win a conflicting limit key. */
    private const GRANT_SOURCE_ORDER = ['paddle_lifetime' => 0, 'stripe_lifetime' => 0, 'manual' => 1, 'promotion' => 2];

    /** Roadmap limit keys backed by the legacy numeric plan columns. */
    private const LEGACY_LIMIT_COLUMNS = [
        'sites'       => 'site_limit',
        'users'       => 'user_limit',
        'ai_insights' => 'ai_insight_limit',
        'reports'     => 'report_limit',
    ];

    public function resolve(Organization $organization): array
    {
        if ($organization->status === 'suspended') {
            return $this->result(null, [], [], 'none', 'suspended');
        }

        $subscription = $this->subscriptionFor($organization);
        $override = (bool) ($subscription?->internal_override_active ?? false);
        $organizationPlan = $organization->relationLoaded('plan')
            ? $organization->getRelation('plan')
            : ($organization->plan_id ? Plan::find($organization->plan_id) : null);

        $plan = null;
        $source = 'none';
        $status = $this->statusEcho($organization, $subscription);

        // A redeemed AppSumo license is the customer's lifetime entitlement.
        // It must win over a stale trial/subscription projection left on an
        // account that existed before the redemption.
        if ($organization->billing_provider === 'license'
            && str_starts_with((string) $organizationPlan?->slug, 'appsumo_')) {
            $plan = $organizationPlan;
            $source = 'license';
            $status = 'active';
        }

        // A platform admin can assign a support/complementary plan without
        // changing the customer's provider subscription. This explicit grant
        // is higher precedence than provider state, is auditable, and is
        // cleared when a new provider subscription projection arrives.
        $adminOverride = $this->adminPlanOverrideFor($organization);
        if ($adminOverride?->plan_id) {
            $plan = Plan::find($adminOverride->plan_id);
            $source = $plan ? 'manual' : 'none';
        }

        if (! $plan && $override) {
            // Admin override: the subscription's plan applies regardless of
            // its own status (suspension was already handled above).
            $plan = $subscription->plan_id ? Plan::find($subscription->plan_id) : null;
            $source = 'subscription';
            $status = 'override';
        } elseif (! $plan && (
            $subscription
            && in_array($subscription->status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)
            && $subscription->plan_id
        )) {
            $plan = Plan::find($subscription->plan_id);
            $source = $plan ? 'subscription' : 'none';
        }

        // New accounts are intentionally provisioned with the explicit Free
        // plan and no subscription. Treat that persisted plan as the default
        // entitlement source so the resolver remains authoritative for Free
        // Connected accounts too. Do not use an org plan as a fallback when a
        // subscription row exists: a cancelled/expired paid subscription
        // must fall back to Free (or an active lifetime grant), not retain a
        // stale paid plan_id from a historical billing projection.
        // No-payment trial: the trial plan applies only while the trial
        // window is open and no live paid subscription decides the plan.
        $liveSubscription = $subscription && in_array($subscription->status, self::ACTIVE_SUBSCRIPTION_STATUSES, true);
        if (! $plan && ! $liveSubscription && $organization->onTrial() && $organization->plan_id) {
            $plan = Plan::find($organization->plan_id);
            $source = $plan ? 'trial' : $source;
            $status = $plan ? 'trialing' : $status;
        }

        if (! $plan && ! $subscription && $organization->plan_id) {
            // AppSumo licenses are provider-neutral lifetime entitlements
            // projected onto organizations with billing_provider=license;
            // unlike a cancelled subscription, that persisted plan remains
            // authoritative until the license webhook deactivates it.
            if ($organizationPlan?->slug === 'free' || $organization->billing_provider === 'license') {
                $plan = $organizationPlan;
                $source = $organizationPlan?->slug === 'free' ? 'free' : 'license';
                $features = $this->strings($plan->features_json);
                $limits = $this->planLimits($plan);
            }
        }

        $features = $plan ? $this->strings($plan->features_json) : [];
        $limits = $plan ? $this->planLimits($plan) : [];

        if ($source === 'license' && $plan?->slug === 'appsumo_tier_3'
            && AppSumoLicense::where('org_id', $organization->id)->where('tier', 3)
                ->where('keeps_tier_three_features', true)->whereIn('license_status', ['active', 'inactive'])->exists()) {
            $features = array_values(array_unique(array_merge($features, ['white_label_reports', 'priority_support'])));
        }

        foreach ($this->activeGrants($organization) as $grant) {
            if ($source === 'none' && $grant->plan_id) {
                // Nothing else decided the plan; a grant carrying one can.
                $grantPlan = Plan::find($grant->plan_id);

                if ($grantPlan) {
                    $plan = $grantPlan;
                    $source = EntitlementGrant::isLifetimeSource($grant->source) ? 'lifetime' : 'grant';
                    $features = $this->strings($plan->features_json);
                    $limits = $this->planLimits($plan);
                }
            } elseif ($source === 'none' && EntitlementGrant::isLifetimeSource($grant->source)) {
                // A plan-less lifetime grant still owns the base payload.
                $source = 'lifetime';
            }

            // Every active grant unions features and overrides limits — a
            // grant may raise or reduce a limit, and null never overrides.
            $features = array_values(array_unique(array_merge($features, $this->strings($grant->features_json))));

            foreach ($this->intMap($grant->limits_json) as $key => $value) {
                $limits[$key] = $value;
            }
        }

        // 0/absent means "caller falls back to its default"; a grant can
        // remove a key by setting it to 0.
        $limits = array_filter($limits, fn (int $value) => $value > 0);

        return $this->result($plan, $features, $limits, $source, $status);
    }

    /** Resolved limit for one key; 0 = unlimited/unknown, handled by callers as today. */
    public function limitFor(Organization $organization, string $key): int
    {
        return $this->resolve($organization)['limits'][$key] ?? 0;
    }

    public function hasFeature(Organization $organization, string $feature): bool
    {
        return in_array($feature, $this->resolve($organization)['features'], true);
    }

    // ------------------------------------------------------------------

    private function result(?Plan $plan, array $features, array $limits, string $source, string $status): array
    {
        return [
            'plan' => [
                'id'   => $plan?->id,
                'slug' => $plan?->slug,
                'name' => $plan?->name,
            ],
            'features' => array_values($features),
            'limits'   => $limits,
            'source'   => $source,
            'status'   => $status,
        ];
    }

    /**
     * Latest subscription for the org, bypassing the tenant scope so webhook,
     * job and admin contexts resolve the same entitlements as user requests.
     * Mirrors Organization::subscription (latestOfMany on id).
     */
    private function subscriptionFor(Organization $organization): ?Subscription
    {
        return Subscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->orderByDesc('id')
            ->first();
    }

    /** Not-revoked, inside-window grants, ordered lifetime -> manual -> promotion. */
    private function activeGrants(Organization $organization): Collection
    {
        $now = now();

        return EntitlementGrant::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('id')
            ->get()
            ->sortBy(fn (EntitlementGrant $grant) => self::GRANT_SOURCE_ORDER[$grant->source] ?? 99)
            ->values();
    }

    private function adminPlanOverrideFor(Organization $organization): ?EntitlementGrant
    {
        return EntitlementGrant::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('source', 'manual')
            ->where('source_reference', 'admin_plan_override:' . $organization->id)
            ->whereNotNull('plan_id')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest('id')
            ->first();
    }

    /** Status echo for the payload: subscription first, org fallback, 'free' when nothing applies. */
    private function statusEcho(Organization $organization, ?Subscription $subscription): string
    {
        if ($subscription) {
            return match ($subscription->status) {
                'active', 'trialing', 'past_due', 'paused', 'canceled', 'suspended' => $subscription->status,
                'unpaid'             => 'past_due',
                'incomplete_expired' => 'canceled',
                default              => $this->orgStatusEcho($organization),
            };
        }

        return $this->orgStatusEcho($organization);
    }

    private function orgStatusEcho(Organization $organization): string
    {
        return in_array($organization->status, ['trialing', 'active', 'past_due', 'paused', 'canceled'], true)
            ? $organization->status
            : 'free';
    }

    /** Plan limits: limits_json / LIMIT_DEFAULTS keys plus the legacy columns under roadmap names. */
    private function planLimits(Plan $plan): array
    {
        $limits = [];

        foreach (array_keys(Plan::LIMIT_DEFAULTS) as $key) {
            $value = $plan->limit($key);

            if ($value > 0) {
                $limits[$key] = $value;
            }
        }

        $json = $plan->limits_json ?? [];

        foreach (self::LEGACY_LIMIT_COLUMNS as $key => $column) {
            $value = (int) (array_key_exists($key, $json) ? $json[$key] : $plan->{$column});

            if ($value > 0) {
                $limits[$key] = $value;
            }
        }

        // Roadmap-era keys (crawl_pages_monthly, tracked_keywords, ...) ride
        // in limits_json without a legacy column — pass them through verbatim.
        foreach ($json as $key => $value) {
            if (isset($limits[$key]) || ! is_numeric($value)) {
                continue;
            }

            if ((int) $value > 0) {
                $limits[$key] = (int) $value;
            }
        }

        return $limits;
    }

    /** features_json -> deduped non-empty string list. */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $features = array_filter(array_map('strval', $value), fn (string $feature) => $feature !== '');

        return array_values(array_unique($features));
    }

    /** limits_json -> int map; null entries dropped so they never override. */
    private function intMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if ($entry !== null) {
                $map[$key] = (int) $entry;
            }
        }

        return $map;
    }
}
