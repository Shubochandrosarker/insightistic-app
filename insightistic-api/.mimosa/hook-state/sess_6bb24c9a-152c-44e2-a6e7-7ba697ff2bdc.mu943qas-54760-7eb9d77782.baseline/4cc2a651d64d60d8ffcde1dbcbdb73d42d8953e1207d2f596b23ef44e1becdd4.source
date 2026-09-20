<?php

namespace App\Domain\Billing;

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
    private const GRANT_SOURCE_ORDER = ['paddle_lifetime' => 0, 'manual' => 1, 'promotion' => 2];

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

        $plan = null;
        $source = 'none';
        $status = $this->statusEcho($organization, $subscription);

        if ($override) {
            // Admin override: the subscription's plan applies regardless of
            // its own status (suspension was already handled above).
            $plan = $subscription->plan_id ? Plan::find($subscription->plan_id) : null;
            $source = 'subscription';
            $status = 'override';
        } elseif (
            $subscription
            && in_array($subscription->status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)
            && $subscription->plan_id
        ) {
            $plan = Plan::find($subscription->plan_id);
            $source = $plan ? 'subscription' : 'none';
        }

        $features = $plan ? $this->strings($plan->features_json) : [];
        $limits = $plan ? $this->planLimits($plan) : [];

        foreach ($this->activeGrants($organization) as $grant) {
            if ($source === 'none' && $grant->plan_id) {
                // Nothing else decided the plan; a grant carrying one can.
                $grantPlan = Plan::find($grant->plan_id);

                if ($grantPlan) {
                    $plan = $grantPlan;
                    $source = $grant->source === 'paddle_lifetime' ? 'lifetime' : 'grant';
                    $features = $this->strings($plan->features_json);
                    $limits = $this->planLimits($plan);
                }
            } elseif ($source === 'none' && $grant->source === 'paddle_lifetime') {
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
