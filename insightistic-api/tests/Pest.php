<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Feature tests boot the full framework and run against a real PostgreSQL
| database (see phpunit.xml) — the schema depends on jsonb/pgvector.
*/

use App\Models\KnowledgeBase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Query\ReadOnlyRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        provisionQueryEngineReadonlyRoleOnce();
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Safe WooCommerce Query Engine (P1) read-only role
|--------------------------------------------------------------------------
| RefreshDatabase's own `afterRefreshingDatabase()` hook can't be used for
| this: Pest's generated Feature test classes `use RefreshDatabase`
| directly, and PHP resolves a trait method used on the class itself ahead
| of one inherited from a parent class — so overriding it on
| Tests\TestCase would be silently shadowed by the trait's own no-op
| implementation. Chaining ->beforeEach() onto the extend() call above
| (rather than a bare top-level beforeEach(), which only scopes to hooks
| declared *inside* a matching test file, not this config file) runs
| reliably instead.
|
| Provisioning also has to go through a deliberately raw, un-wrapped PDO
| connection rather than Laravel's DB facade: RefreshDatabase wraps every
| test in a transaction on the default connection that it rolls back at
| teardown, which would silently undo a GRANT issued through that
| connection. This mirrors migrate:fresh's own effect (dropping and
| recreating every table wipes their GRANTs too) — re-provisioning once
| per process, after the schema exists, is what actually sticks.
*/
function provisionQueryEngineReadonlyRoleOnce(): void
{
    static $provisioned = false;

    if ($provisioned || config('database.default') !== 'pgsql') {
        return;
    }
    $provisioned = true; // attempt at most once per process either way

    $role = env('READONLY_DB_USERNAME');
    $password = env('READONLY_DB_PASSWORD');

    if (! $role || ! $password) {
        return;
    }

    $config = config('database.connections.pgsql');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
    $pdo = new PDO($dsn, $config['username'], $config['password']);

    (new ReadOnlyRoleProvisioner)->provision($pdo, $config['database'], $role, $password);
}

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
*/

/**
 * Create an active user + organization (owner role), optionally on a plan
 * with specific limits_json overrides. Returns [user, org].
 *
 * @return array{0: User, 1: Organization}
 */
function makeOrgUser(string $email, ?array $limits = null, string $role = 'owner'): array
{
    $plan = Plan::create([
        'name' => 'Test Plan',
        'slug' => 'test-'.Str::random(8),
        'site_limit' => 5,
        'user_limit' => 5,
        'ai_insight_limit' => 100,
        'report_limit' => 100,
        'limits_json' => $limits,
        'is_active' => true,
    ]);

    $user = User::create([
        'name' => 'User '.$email,
        'email' => $email,
        'password' => 'secret-password',
        'status' => 'active',
    ]);

    $org = Organization::create([
        'name' => 'Org '.$email,
        'slug' => 'org-'.Str::random(8),
        'owner_user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    $org->users()->attach($user->id, ['role' => $role]);

    return [$user, $org];
}

/** Create a knowledge base directly (bypassing HTTP) for a given org. */
function makeKnowledgeBase(Organization $org, string $name = 'Docs'): KnowledgeBase
{
    return KnowledgeBase::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => $name,
        'scope' => 'organization',
        'status' => 'active',
    ]);
}
