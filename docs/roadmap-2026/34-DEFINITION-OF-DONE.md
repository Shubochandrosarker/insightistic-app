# Definition of Done

A feature is not done when the page renders. Use these gates.

## Product gate

- user problem and outcome documented
- plan/entitlement decided
- empty/error/degraded states defined
- usage/cost impact defined

## Backend gate

- migration/model complete
- tenant scoping enforced
- validation implemented
- authorization implemented
- idempotency defined
- provider errors normalized
- usage enforcement server-side

## Frontend gate

- responsive page
- loading state
- not-connected state
- syncing state where relevant
- no-data state
- error/retry state
- stale-data indicator
- accessible controls

## Operations gate

- logs/metrics
- retry behavior
- failed-job behavior
- admin visibility
- runbook entry for likely failure

## Security gate

- secrets handling reviewed
- PII minimized
- cross-tenant tests
- injection/XSS/SSRF concerns reviewed

## Test gate

- unit tests for core logic
- API/integration tests
- frontend tests for critical states
- E2E for major paid flow when applicable

## Documentation gate

- API contract updated
- environment variables documented
- user-facing help/copy updated
- changelog/release notes

## Billing feature gate

Any feature connected to Paddle is done only when:

- sandbox checkout works
- signature verification works
- duplicate webhook safe
- reconciliation exists
- entitlement changes correctly
- cancellation/past-due states verified

## WordPress feature gate

- capability + nonce
- sanitize/escape
- multisite behavior known
- no frontend performance regression
- release validation passes
