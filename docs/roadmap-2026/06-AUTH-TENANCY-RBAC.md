# Authentication, Tenancy & RBAC

## Objectives

- prevent cross-tenant data access
- support agencies managing many sites
- support client-viewer access
- support secure WordPress machine authentication
- preserve admin break-glass capability without mixing it into normal tenant flows

## Human authentication

### Supported flows

- email/password
- password reset
- optional Google/Microsoft/GitHub OAuth
- TOTP MFA
- recovery codes
- session/token listing and revoke

### Token policy

The existing Sanctum bearer-token approach can remain. Requirements:

- tokens have expiry
- privileged actions require recent reauthentication
- disabled/suspended users cannot obtain or continue privileged sessions
- tokens are revocable individually

## Organization selection

A user may belong to multiple organizations.

Every authenticated tenant request resolves one organization using a validated organization identifier/header or explicit route context. Never accept an arbitrary organization ID without membership verification.

## Roles

### Owner

Can:

- manage billing
- manage plan
- manage organization
- issue/regenerate license
- manage team and all sites
- manage integrations
- manage exports/deletion

### Admin

Can:

- manage sites/integrations
- manage most team operations except ownership/billing-sensitive actions
- view all organization analytics

### Analyst

Can:

- view analytics
- run crawls/reports where allowed
- generate insights
- manage tracked keywords/prompts if granted

Cannot manage billing or organization ownership.

### Client viewer

Read-only access to explicitly granted sites. Sensitive customer-level commerce reports may be restricted.

## Site-level grants

Agency client users should not automatically see every site in the organization.

`site_user_access` remains the source of truth for restricted roles.

## WordPress connector authentication

Use the existing per-site HMAC model.

### Activation

A one-time license key is exchanged for:

- connector key ID
- connector secret
- site ID
- activation ID
- entitlement state

The raw license key is not stored in WordPress after activation.

### Signed requests

Canonical request format remains:

```text
METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256(BODY)
```

Server validation:

1. resolve key ID
2. load/decrypt secret
3. verify timestamp window
4. reject replayed nonce
5. compute signature
6. constant-time compare
7. establish site + organization tenancy

## Super admin

Super-admin access must be separate from normal organization roles.

Requirements:

- explicit `is_super_admin`
- audited promotion/demotion
- MFA required
- reauthentication for sensitive mutations
- impersonation sessions logged with actor and reason
- impersonation UI clearly marked

## Authorization implementation rules

- validate with policies/middleware server-side
- never rely on hidden buttons for security
- authorization tests for every sensitive route
- queued jobs must re-resolve and validate tenant resources
- exports must re-check access at generation time

## Security-sensitive operations requiring recent reauth

At minimum:

- billing plan change/cancel
- lifetime entitlement/manual billing override
- license regeneration
- MFA changes
- API token creation
- data export/deletion
- super-admin promotion
- impersonation start

## Acceptance criteria

- a user in Org A cannot retrieve Org B data even with guessed IDs
- connector credential for Site A cannot ingest into Site B
- client viewer only sees granted sites
- deactivated user cannot continue using a previously valid session indefinitely
- privileged actions fail without reauth when reauth window expired
