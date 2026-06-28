<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bootstraps a brand-new account's first organization (owner + trial + usage
 * counter) in one transaction. Shared by password signup and OAuth signup so
 * both flows produce an identical, usable account.
 */
class AccountProvisioner
{
    public function bootstrapOrganization(User $user, string $organizationName): Organization
    {
        return DB::transaction(function () use ($user, $organizationName) {
            $starter = Plan::where('slug', 'starter')->first();

            $org = Organization::create([
                'name'          => $organizationName,
                'slug'          => $this->uniqueSlug($organizationName),
                'owner_user_id' => $user->id,
                'plan_id'       => $starter?->id,
                'status'        => 'trialing',
                'trial_ends_at' => now()->addDays(14),
            ]);

            $org->users()->attach($user->id, ['role' => 'owner']);

            UsageCounter::firstOrCreate(
                ['organization_id' => $org->id, 'period' => now()->format('Y-m')],
                ['sites_connected' => 0],
            );

            return $org;
        });
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
