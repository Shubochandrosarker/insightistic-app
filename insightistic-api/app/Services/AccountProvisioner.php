<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bootstraps a brand-new account's first organization (owner + usage counter
 * + auto-generated license) in one transaction. Shared by password signup
 * and OAuth signup so both flows produce an identical, usable account.
 *
 * Every new org starts on the 'free' plan with status 'active' and no
 * trial_ends_at — free forever, matching what the WordPress plugin promises
 * ("Create a free account — no card, free forever") for AI Insights and
 * email automation. Nothing here expires; upgrading to a paid plan is a
 * separate, explicit action via BillingController, not something that
 * happens automatically when a trial lapses.
 */
class AccountProvisioner
{
    public function __construct(private LicenseService $licenses)
    {
    }

    /**
     * @param string|null $rawLicenseKey Set to the one-time raw license key —
     *                                   the only moment it exists in plaintext.
     *                                   Callers show it once (register response
     *                                   / onboarding) and email it.
     */
    public function bootstrapOrganization(User $user, string $organizationName, ?string &$rawLicenseKey = null): Organization
    {
        $rawKey = null;

        $org = DB::transaction(function () use ($user, $organizationName, &$rawKey) {
            $free = Plan::where('slug', 'free')->first();

            $org = Organization::create([
                'name'          => $organizationName,
                'slug'          => $this->uniqueSlug($organizationName),
                'owner_user_id' => $user->id,
                'plan_id'       => $free?->id,
                'status'        => 'active',
                'trial_ends_at' => null,
            ]);

            $org->users()->attach($user->id, ['role' => 'owner']);

            UsageCounter::firstOrCreate(
                ['organization_id' => $org->id, 'period' => now()->format('Y-m')],
                ['sites_connected' => 0],
            );

            // Every account gets a license automatically (spec §4 signup flow).
            $rawKey = $this->licenses->issue($org->fresh('plan'));

            return $org;
        });

        $rawLicenseKey = $rawKey;

        return $org;
    }

    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 1;
        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
