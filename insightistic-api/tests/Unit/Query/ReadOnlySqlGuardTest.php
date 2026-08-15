<?php

use App\Query\QueryEngineException;
use App\Query\ReadOnlySqlGuard;

/**
 * Defense-in-depth checks on compiled SQL text (see ReadOnlySqlGuard's
 * docblock for why this is secondary, not primary, defense). Mirrors the
 * adversarial vector list in docs/04-LOCAL-QUERY-SECURITY.md, adapted to
 * Postgres syntax where the docs use MySQL examples (this app is Postgres-only).
 */
function guard(): ReadOnlySqlGuard
{
    return new ReadOnlySqlGuard;
}

function allowedTables(): array
{
    return ['metric_snapshots', 'wc_orders', 'wc_order_items'];
}

it('accepts a well-formed single SELECT against allowlisted tables', function () {
    guard()->assertSafe('select "site_id", COALESCE(SUM(revenue),0) as revenue from "metric_snapshots" where "site_id" = ?', allowedTables());
    guard()->assertSafe('select * from "wc_order_items" as "oi" inner join "wc_orders" as "o" on "o"."external_order_id" = "oi"."external_order_id"', allowedTables());
})->throwsNoExceptions();

it('rejects stacked/multi-statement queries', function () {
    guard()->assertSafe('select * from metric_snapshots; DROP TABLE metric_snapshots;', allowedTables());
})->throws(QueryEngineException::class);

it('rejects line-comment injection attempts', function (string $sql) {
    guard()->assertSafe($sql, allowedTables());
})->throws(QueryEngineException::class)->with([
    "select * from metric_snapshots -- ' or 1=1",
    'select * from metric_snapshots # trailing mysql-style comment',
]);

it('rejects block-comment injection attempts', function () {
    guard()->assertSafe('select * from metric_snapshots /* comment */ where site_id = 1', allowedTables());
})->throws(QueryEngineException::class);

it('rejects UNION-based extraction, including mixed case', function (string $sql) {
    guard()->assertSafe($sql, allowedTables());
})->throws(QueryEngineException::class)->with([
    'select revenue from metric_snapshots UNION select password from users',
    'select revenue from metric_snapshots Union All select password from users',
]);

it('rejects write/DDL/administrative statements even alone', function (string $sql) {
    guard()->assertSafe($sql, allowedTables());
})->throws(QueryEngineException::class)->with([
    'insert into metric_snapshots (site_id) values (1)',
    'update metric_snapshots set revenue = 0',
    'delete from metric_snapshots',
    'drop table metric_snapshots',
    'alter table metric_snapshots add column x int',
    'truncate metric_snapshots',
    'grant select on metric_snapshots to public',
    'revoke select on metric_snapshots from public',
    "copy metric_snapshots to '/tmp/x.csv'",
    'select * from metric_snapshots; call some_procedure()',
]);

it('rejects delay/exfiltration/introspection primitives', function (string $sql) {
    guard()->assertSafe($sql, allowedTables());
})->throws(QueryEngineException::class)->with([
    'select pg_sleep(10)',
    "select benchmark(1000000, md5('x'))",
    "select * from pg_read_file('/etc/passwd')",
    'select * from information_schema.tables',
    'select * from pg_catalog.pg_shadow',
]);

it('rejects a table outside the allowlist even with a syntactically clean SELECT', function (string $sql) {
    guard()->assertSafe($sql, allowedTables());
})->throws(QueryEngineException::class)->with([
    'select * from users',
    'select * from "organizations"',
    'select * from metric_snapshots join users on users.id = metric_snapshots.site_id',
]);

it('rejects an empty query', function () {
    guard()->assertSafe('   ', allowedTables());
})->throws(QueryEngineException::class);

it('rejects a query that does not start with SELECT', function (string $sql) {
    guard()->assertSafe($sql, allowedTables());
})->throws(QueryEngineException::class)->with([
    'explain select * from metric_snapshots',
    '(select * from metric_snapshots)',
    'with x as (select 1) delete from metric_snapshots',
]);
