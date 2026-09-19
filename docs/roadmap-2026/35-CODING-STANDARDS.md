# Coding Standards & Implementation Instructions

## General

- production code only; no placeholder success paths
- small cohesive services
- explicit names
- document non-obvious business rules
- prefer tests around behavior over implementation detail

## Laravel/PHP

- Laravel 12 conventions
- PHP 8.3+ syntax compatible with production target
- Form Requests for complex validation
- policies/middleware for authorization
- transactions for multi-row state transitions
- queued jobs for slow provider work
- `insertOrIgnore`/UPSERT patterns where event idempotency matters
- encrypt provider secrets using Laravel encrypted casts/service
- avoid logging raw provider responses when they contain customer data/secrets

## Next.js/React

- TypeScript strictness
- server/API data types centralized
- do not hardcode plan prices/price IDs in components
- reusable table/state components
- avoid client-side authorization as a security boundary
- keep chart data transformation in testable utilities/hooks
- no secret env variables prefixed/publicly exposed

## WordPress/PHP

Follow WordPress Coding Standards.

### Input

- `sanitize_text_field`
- `sanitize_key`
- `absint`
- URL/email-specific sanitizers as applicable

### Output

- `esc_html`
- `esc_attr`
- `esc_url`
- carefully allowlisted `wp_kses` when HTML required

### State changes

- capability checks
- nonces

### Database

- use `$wpdb->prepare()` for raw SQL
- prefer WordPress APIs where appropriate

### Hooks

- no core modification
- namespaced/prefixed hooks
- avoid expensive work directly in frontend request lifecycle

## API/provider code

Every provider client must include:

- timeout
- retry classification
- rate-limit awareness
- structured errors
- redacted logging

## Naming

### Database

snake_case.

### JSON API

snake_case to match Laravel conventions unless an existing contract requires otherwise.

### TypeScript

camelCase internally; map API fields explicitly when useful rather than mixing conventions unpredictably.

## Comments

Comment:

- why a security/business rule exists
- provider-specific surprising behavior
- methodology caveats

Do not comment obvious syntax.

## Feature flags

Use for risky subsystems:

- Paddle checkout cutover
- crawler
- rank tracker
- AI visibility

Flags must have clear removal conditions.
