<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Proves the "read-only identity must be unable to write even if every
 * application validator fails" requirement at the database level — not by
 * asserting the app never sends a write, but by attempting one directly
 * against the `readonly` Postgres role and confirming Postgres itself
 * rejects it.
 *
 * Requires `php artisan db:provision-readonly-role` to have been run for
 * the current test database (see docs/SAFE-QUERY-ENGINE.md and
 * .github/workflows/ci.yml). Skips gracefully — rather than failing the
 * whole suite — when that role doesn't exist, e.g. for a contributor who
 * hasn't provisioned it locally yet.
 */
beforeEach(function () {
    if (! config('database.connections.readonly.username')) {
        $this->markTestSkipped('READONLY_DB_USERNAME not configured.');
    }

    try {
        DB::connection('readonly')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('readonly role not provisioned: '.$e->getMessage());
    }
});

it('can SELECT from an allowlisted table', function () {
    // No rows required — this just proves CONNECT + USAGE + SELECT grants exist.
    $result = DB::connection('readonly')->select('select count(*) as c from metric_snapshots');

    expect($result[0]->c)->toBeInt();
});

it('cannot INSERT even with a hand-crafted statement', function () {
    DB::connection('readonly')->insert(
        'insert into metric_snapshots (site_id, date, revenue, orders) values (999999, ?, 1, 1)',
        [now()->toDateString()]
    );
})->throws(QueryException::class);

it('cannot UPDATE', function () {
    DB::connection('readonly')->update('update metric_snapshots set revenue = 0 where site_id = 999999');
})->throws(QueryException::class);

it('cannot DELETE', function () {
    DB::connection('readonly')->delete('delete from metric_snapshots where site_id = 999999');
})->throws(QueryException::class);

it('cannot DROP or ALTER the schema', function () {
    DB::connection('readonly')->statement('alter table metric_snapshots add column x int');
})->throws(QueryException::class);

it('cannot read a table outside the query engine allowlist', function () {
    DB::connection('readonly')->select('select * from users limit 1');
})->throws(QueryException::class);

it('is a genuinely different role from the default write connection', function () {
    $readonlyUser = DB::connection('readonly')->selectOne('select current_user as u')->u;
    $defaultUser = DB::connection()->selectOne('select current_user as u')->u;

    expect($readonlyUser)->not->toBe($defaultUser);
});
