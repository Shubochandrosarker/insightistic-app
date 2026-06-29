# Super Admin, Root Redirect & Mobile App Shell

This covers the platform-owner admin area, the app-root redirect behavior, and
the mobile dashboard experience.

## Root redirect (app.insightistic.com/)

The app subdomain is the SaaS app only — the marketing site lives on WordPress at
`insightistic.com`. The root route never renders a marketing page; it shows a
brief "Preparing your workspace…" loader, then redirects:

| State | Redirect |
|---|---|
| Not logged in | `/login` |
| Logged-in normal user | `/dashboard` |
| Logged-in super admin | `/admin` |

Authenticated users who hit `/login` or `/register` are bounced to their
dashboard (or `/admin`) automatically.

## Create the first Super Admin

Super admin is a backend-only elevation (never togglable from the UI). Use the
artisan command on the API server:

```bash
# Promote an existing user
php artisan insightistic:make-super-admin owner@example.com

# Create the user and promote in one step
php artisan insightistic:make-super-admin owner@example.com \
  --create --name="Shuvo Sarkar" --password="strong-password"
```

If `--password` is omitted with `--create`, a strong password is generated and
printed once. After this, sign in at `https://app.insightistic.com/login` and
open **`/admin`**.

## Admin area (`/admin`)

Guarded by `is_super_admin`. Normal users, owners, admins, analysts and client
viewers are blocked (redirect to `/login` if unauthenticated, 403 screen if
authenticated but not a super admin).

Pages: Overview, Organizations (+ detail), Users, Sites (+ detail),
Subscriptions, Sync Logs, Reports, Usage, System Health, Settings.

What you can do: change an org's plan, suspend/reactivate an org, enable/disable
a user, search/filter/paginate every list, and read platform health. Secrets
(passwords, connector secrets, Stripe secret keys) are never returned.

## Admin API (`/api/admin/*`)

All endpoints require `auth:sanctum` + the `super_admin` middleware and are
**cross-tenant** (no org scope). Lists are paginated with `search`, filters and
`sort`/`dir`.

```
GET   /api/admin/overview
GET   /api/admin/system-health
GET   /api/admin/organizations            ?search=&plan=&status=&page=
GET   /api/admin/organizations/{id}
PATCH /api/admin/organizations/{id}        { plan?, status? }
GET   /api/admin/users                     ?search=&super_admin=&status=&organization_id=&role=
GET   /api/admin/users/{id}
PATCH /api/admin/users/{id}                 { status }   # enable/disable only
GET   /api/admin/sites                      ?search=&organization_id=&connection_status=
GET   /api/admin/sites/{id}
GET   /api/admin/subscriptions              ?status=&organization_id=&canceled=
GET   /api/admin/sync-logs                  ?site_id=&organization_id=&status=&job=&search=
GET   /api/admin/reports                    ?organization_id=&site_id=&report_type=&from=&to=
GET   /api/admin/usage                      ?search=&plan=
```

## Mobile dashboard (app-style)

The dashboard and admin areas share one responsive shell
(`components/app-shell/*`):

- **Desktop (≥1024px):** left sidebar + top bar + content.
- **Mobile (<1024px):** sticky compact header (brand, site switcher, period,
  theme, avatar), single-column content, and a **fixed bottom tab bar** (5
  items, the 5th opens a slide-up "More" sheet). Safe-area aware
  (`padding-bottom: calc(80px + env(safe-area-inset-bottom))`), 44px+ tap
  targets, glassy blur, animated active states.
- **Tables** render as stacked cards on mobile (`ResponsiveTable`) so nothing
  overflows; metric cards use a 2-column grid; charts use responsive containers.

## Deploy

```bash
# API
cd insightistic-api && php artisan migrate --force && php artisan optimize

# App
cd insightistic-app && npm install && npm run build && pm2 restart insightistic-app
```

Smoke test:

```bash
curl -I https://app.insightistic.com/login
curl -I https://app.insightistic.com/dashboard
curl -I https://app.insightistic.com/admin
curl    https://app.insightistic.com/api/auth/oauth/providers
```
