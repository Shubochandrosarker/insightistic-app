# Security, Privacy & Compliance

## Security model

Insightistic handles high-value credentials and business analytics. Security must be designed into provider integrations, billing, WordPress sync and AI workflows.

## Secrets

Store encrypted at rest:

- Google refresh/access credentials as required
- Cloudflare tokens
- connector HMAC secrets
- AI provider API keys if users bring their own
- Paddle server API key in environment/secret manager, not DB where unnecessary
- Paddle webhook secret in environment/secret manager

Never expose server secrets in:

- Next.js public env vars
- localized WordPress JS
- logs
- support exports

## Paddle-specific security

- API key server only
- client-side token only for Paddle.js
- verify `Paddle-Signature` against raw body
- unique webhook event ID
- separate sandbox/live secrets
- least-privilege key permissions

## WordPress security

- capability checks
- nonces for admin mutations
- sanitize input
- validate types/ranges
- escape output late
- avoid arbitrary remote URL fetch without SSRF controls

## SaaS application security

### Tenant isolation

- organization global scope/explicit constraints
- policies/middleware
- cross-tenant tests

### SSRF protection

Crawler is intentionally allowed to fetch public websites, but knowledge/import tools and arbitrary URL features must prevent access to:

- localhost
- RFC1918/private networks
- link-local metadata services
- internal Docker/service hostnames
- credential-bearing URLs

Crawler should also protect against DNS rebinding and redirect-to-private targets.

### Authentication

- MFA
- token expiry/revoke
- reauth for sensitive actions
- login anomaly notifications where existing implementation supports them

## Privacy data categories

### Account data

- name/email
- organization/team data

### Site data

- URLs/content metadata
- provider analytics
- WordPress environment metadata

### Commerce data

- order/product/customer analytics
- minimize customer PII

### AI data

- prompts
- evidence bundles
- model outputs

Document which third-party AI provider receives what data.

## Data minimization

- hash customer email where only repeat identity is needed
- do not ingest card/payment-token data from WooCommerce
- do not store raw crawl HTML by default
- do not store raw IP/user-agent for analytics unless a specific lawful/necessary feature requires it

## Retention

Configure by plan/domain:

- normalized analytics history
- crawl observations
- uptime checks (aggregate older raw checks)
- AI run details
- audit logs
- billing events

Deletion requests must remove tenant data while preserving records that must legally/operationally remain, with policy documented.

## Logging

Scrub:

- Authorization headers
- cookies
- tokens
- passwords
- Paddle API keys
- webhook secrets
- Google/Cloudflare credentials
- customer PII where unnecessary

## Backups

- encrypted backup storage
- documented retention
- restoration tests
- no secrets in plaintext dumps distributed to developers

## Incident response minimum

1. detect
2. contain/revoke credentials
3. identify affected tenants/data
4. restore service
5. communicate according to obligations
6. postmortem and corrective action
