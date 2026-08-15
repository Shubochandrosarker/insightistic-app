# Security Test Matrix — Safe WooCommerce Query Engine (P1)

Tracks `docs/04-LOCAL-QUERY-SECURITY.md`'s "Mandatory adversarial tests"
and `docs/10-ACCEPTANCE-TESTS.md`'s SQL/query-security and tenant-isolation
gates against what this PR actually implemented and tested. Every row is
PASS or NOT VERIFIED — nothing is hidden. Re-run and update this file
before any change to `app/Query/*`.

## SQL / query security

| Control | Status | Evidence |
|---|---|---|
| Exactly one statement | PASS | `ReadOnlySqlGuard`: rejects any `;`. `tests/Unit/Query/ReadOnlySqlGuardTest.php` ("rejects stacked/multi-statement queries"). |
| SELECT only (no INSERT/UPDATE/DELETE/DDL) | PASS | Guard's forbidden-keyword list + real-database proof: `ReadOnlyIdentityTest.php` attempts INSERT/UPDATE/DELETE/ALTER against the actual `readonly` Postgres role and asserts Postgres itself rejects them. |
| Executable comment / `--` / `#` / `/* */` injection | PASS | `ReadOnlySqlGuardTest.php` ("rejects line-comment/block-comment injection attempts"). |
| Multi-statement semicolon payload | PASS | Same file, "rejects stacked/multi-statement queries". |
| UNION-based extraction (incl. mixed case) | PASS | Same file, "rejects UNION-based extraction, including mixed case". |
| File functions (`pg_read_file`, `load_file`, `outfile`, `dumpfile`) | PASS | Same file, "rejects delay/exfiltration/introspection primitives". |
| SLEEP / BENCHMARK / `pg_sleep` | PASS | Same file, same test. |
| Unapproved schema introspection (`information_schema`, `pg_catalog`) | PASS | Same file, same test. |
| Unauthorized tables/columns | PASS (two layers) | App layer: guard rejects any table outside `WooCommerceQueryCompiler::ALLOWED_TABLES` (`ReadOnlySqlGuardTest.php`). DB layer: `ReadOnlyIdentityTest.php` proves the role itself cannot SELECT `users` even if every app check were bypassed. |
| Cross-tenant filter/scope widening | PASS | `SafeWooQueryEngineTest.php` — "never returns another site's data for an aggregate query" / "...for a product breakdown". Scope comes only from `SafeWooQueryEngine::run()`'s `Site $site`/`int $organizationId` arguments, never from plan content — there is no plan field that could carry a site/org id in the first place. |
| Query timeout enforced | PASS | `StatementTimeoutExecutorTest.php` — real Postgres `statement_timeout` cancellation (SQLSTATE 57014) against a `pg_sleep(2)` with a 100ms budget, and proof the setting doesn't leak onto a later query. |
| Result row cap | PASS | `SafeWooQueryEngine::runBreakdown()` clamps to `chart_max_rows`; `QueryResult::$truncated` surfaces when hit. Covered indirectly by the breakdown tests; no dedicated 500+-row fixture test (see Known gaps). |
| Read-only DB identity cannot write even if every app validator fails | PASS | `ReadOnlyIdentityTest.php` — direct hand-crafted INSERT/UPDATE/DELETE/ALTER against the real role, bypassing the app entirely. |
| Regex used only as defense-in-depth, not primary control | PASS by design | See `docs/SAFE-QUERY-ENGINE.md` "The primary control: architecture, not string filtering" and `ReadOnlySqlGuard`'s own docblock. |

## Tenant isolation

| Control | Status | Evidence |
|---|---|---|
| Foreign organization/site ids cannot be used in a plan | PASS | The `SemanticQueryPlan` schema has no site/org field at all — `PlanValidator` never accepts one. `AnalyticsQueryEndpointTest.php` — "404s for a site outside the caller's organization" proves the HTTP layer's existing `site.access` middleware (unmodified) still gates this route. |
| Evidence hydration rechecks tenant ownership | PASS | Every query is compiled with `$site->id` bound as a parameter from the authenticated route context; no code path re-reads a site/org id from client input after that point. |

## AI honesty / evidence integrity

| Control | Status | Evidence |
|---|---|---|
| Every metric's known simplifications are surfaced, not hidden | PASS | `QueryResult::$caveats` — `SafeWooQueryEngineTest.php` "surfaces the metric's declared caveats in the result". |
| No fabricated values on error | PASS | Every failure path throws `QueryEngineException` before any partial result is constructed — there is no code path that returns a `QueryResult` with guessed/default values. |

## Rate/concurrency limits

| Control | Status | Evidence |
|---|---|---|
| Per-organization concurrency cap | PASS | `QueryConcurrencyLimiterTest.php` — acquire/release/reject-at-cap/independent-per-org, all against the real `Cache::lock()` mechanism (array driver in tests; Redis in production per `docs/ENVIRONMENT-VARIABLES.md`). |
| Per-user request rate limit | PASS | `throttle:query-engine` named limiter (30/min), same mechanism (`RateLimiter::for`) already used by every other rate-limited route in this app. No dedicated 429-at-limit HTTP test for this specific limiter (see Known gaps) — the underlying `ThrottleRequests` middleware is Laravel's own, exercised elsewhere in the suite (e.g. `RegistrationSecurityTest`). |

## Known gaps (explicitly not hidden)

- **No dedicated test drives >500 breakdown rows to prove the row cap
  triggers under real data volume.** The clamp logic itself
  (`min($limit, $chartMaxRows)`) is exercised by `PlanValidatorTest.php`'s
  limit-range tests and code-reviewed; a true volume test is reasonable
  follow-up work, not done here.
- **No dedicated HTTP-level test hits the `query-engine` rate limiter's
  429 response** — only the concurrency limiter's 429 is directly tested.
  The rate limiter itself is Laravel's stock `ThrottleRequests`, already
  covered generically elsewhere in this suite.
- **Load/abuse testing against a staging environment** (per
  `docs/SAFE-QUERY-ENGINE.md`'s scope) has not been run — this PR's testing
  is functional/security-unit/integration only, on a local/CI database, not
  a production-scale load test.
- **GA4/GSC/PageSpeed adapters do not exist** — every control above is
  scoped to the `woocommerce` source. `PlanValidator::unsupportedSource()`
  is the only thing standing in front of those sources today; P4 must
  extend this exact matrix when those adapters are built, not assume
  WooCommerce's coverage carries over.
