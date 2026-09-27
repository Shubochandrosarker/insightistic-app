<?php

namespace App\Services\Ai;

use App\Domain\Billing\EntitlementResolver;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;

/** Resolves the model tier from effective billing entitlements, never UI input. */
class AiTierResolver
{
    public function __construct(private EntitlementResolver $entitlements) {}

    /** @return array{tier: string, plan_slug: string, gateway_tier: string} */
    public function resolve(?int $organizationId): array
    {
        $free = ['tier' => 'free', 'plan_slug' => 'free', 'gateway_tier' => 'free'];

        if (! $organizationId) {
            return $free;
        }

        $organization = Organization::withoutGlobalScope('organization')->find($organizationId);

        if (! $organization || $organization->status === 'suspended') {
            return $free;
        }

        $subscription = Subscription::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->first();

        // Trials use the free model pool unless an admin has explicitly
        // granted a paid override. This keeps signup trials from incurring
        // the paid-model cost tier by default.
        if (($organization->status === 'trialing' || $subscription?->status === 'trialing')
            && ! $subscription?->internal_override_active) {
            return $free;
        }

        $resolved = $this->entitlements->resolve($organization);
        $planId = $resolved['plan']['id'] ?? null;
        $plan = $planId ? Plan::find($planId) : null;

        if (! $plan || (int) $plan->price_monthly <= 0) {
            return $free;
        }

        $gatewayTier = match ($plan->slug) {
            'starter' => 'starter',
            'growth', 'business' => 'pro',
            'agency', 'agency_pro' => 'agency',
            default => 'pro',
        };

        return ['tier' => 'paid', 'plan_slug' => $plan->slug, 'gateway_tier' => $gatewayTier];
    }

    public function isPaid(?int $organizationId): bool
    {
        return $this->resolve($organizationId)['tier'] === 'paid';
    }

    public function gatewayTier(?int $organizationId): string
    {
        return $this->resolve($organizationId)['gateway_tier'];
    }
}
