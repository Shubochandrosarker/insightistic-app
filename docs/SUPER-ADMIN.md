# Super Admin

Platform-owner access (`/admin`, `/api/admin/*`). `is_super_admin` is a
backend-only flag — it is **never** grantable from public surfaces.

> **Security change (2026-07):** the old `SUPER_ADMIN_EMAIL` auto-promotion
> (at signup, via OAuth, and via `SuperAdminSeeder` on every deploy) was
> removed — it let whoever registered the configured address first become
> platform admin. The seeder is now a no-op; the env var does nothing.

## Creating the first super admin (server shell, once)

```bash
php artisan insightistic:make-super-admin owner@example.com
# or create the account at the same time:
php artisan insightistic:make-super-admin owner@example.com --create --name="Owner" --password="..."
```

Once an active super admin exists, this command refuses further promotions
unless `--force` (break-glass). Every path writes to `admin_audit_logs`.

## Promoting additional admins

- **Dashboard (preferred):** an existing super admin calls
  `POST /api/admin/users/{id}/promote` (audited; demote/archive respect the
  last-active-super-admin guard).
- **Bootstrap token (no shell-into-DB):**
  ```bash
  php artisan insightistic:super-admin:issue-token person@example.com --ttl=15
  ```
  Prints a single-use token (hash-stored, expiring). The target user, while
  signed in as that exact account, redeems it via
  `POST /api/auth/super-admin/redeem {"token": "..."}`. First redemption
  attempt burns it; mismatched users are rejected and audited.

## Guarantees (regression-tested)

- Public registration and OAuth signup can never set `is_super_admin`
  (`RegistrationSecurityTest`, `OAuthSecurityTest`).
- `is_super_admin` is not mass-assignable.
- Bootstrap tokens are single-use, expiring, bound to one user
  (`SuperAdminBootstrapTest`).
- The last active super admin cannot be demoted/disabled/archived.

## Routing

`/` → logged-out: `/login`; user: `/dashboard`; super admin: `/admin`.
All `/api/admin/*` routes require `auth:sanctum` + `super_admin` and are
cross-tenant by design. Impersonation requires a reason, is audited, and
impersonated tokens are write-restricted (`RestrictImpersonation`).
