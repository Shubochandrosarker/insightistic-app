<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Feature tests boot the full framework and run against a real PostgreSQL
| database (see phpunit.xml) — the schema depends on jsonb/pgvector.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
*/

/**
 * Create an active user + organization (owner role), optionally on a plan
 * with specific limits_json overrides. Returns [user, org].
 *
 * @return array{0: \App\Models\User, 1: \App\Models\Organization}
 */
function makeOrgUser(string $email, ?array $limits = null, string $role = 'owner'): array
{
    $plan = \App\Models\Plan::create([
        'name'        => 'Test Plan',
        'slug'        => 'test-' . \Illuminate\Support\Str::random(8),
        'site_limit'  => 5,
        'user_limit'  => 5,
        'ai_insight_limit' => 100,
        'report_limit' => 100,
        'limits_json' => $limits,
        'is_active'   => true,
    ]);

    $user = \App\Models\User::create([
        'name'     => 'User ' . $email,
        'email'    => $email,
        'password' => 'secret-password',
        'status'   => 'active',
    ]);

    $org = \App\Models\Organization::create([
        'name'          => 'Org ' . $email,
        'slug'          => 'org-' . \Illuminate\Support\Str::random(8),
        'owner_user_id' => $user->id,
        'plan_id'       => $plan->id,
        'status'        => 'active',
    ]);

    $org->users()->attach($user->id, ['role' => $role]);

    return [$user, $org];
}

/** Create a knowledge base directly (bypassing HTTP) for a given org. */
function makeKnowledgeBase(\App\Models\Organization $org, string $name = 'Docs'): \App\Models\KnowledgeBase
{
    return \App\Models\KnowledgeBase::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name'            => $name,
        'scope'           => 'organization',
        'status'          => 'active',
    ]);
}
