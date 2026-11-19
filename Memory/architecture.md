# Insightistic architecture record

## Product boundaries

Insightistic is intentionally split into independent boundaries:

```text
Public visitor
   │
   └── www.insightistic.com (WordPress marketing site on business hosting)

Customer browser
   │
   └── app.insightistic.com (Next.js dashboard on VPS)
          │ same-origin proxy / authenticated API calls
          └── api.insightistic.com (Laravel API on VPS)
                 ├── PostgreSQL + pgvector
                 ├── Redis
                 ├── queue worker
                 ├── scheduler
                 └── internal Nginx / Traefik routing

Customer WordPress site
   │
   └── Insightistic connector plugin → authenticated API/license endpoints
```

## Repository boundaries

- `insightistic-app/`: Next.js 16 frontend, React 19, TypeScript, Vitest, dashboard/auth/Copilot/knowledge UI.
- `insightistic-api/`: Laravel 12, PHP 8.3+, PostgreSQL, Redis, Sanctum, Socialite, billing, reporting, AI, scheduled jobs, and integration endpoints.
- `insightistic/`: WordPress/WooCommerce connector and licensing code in this working tree; verify canonical plugin repository and release process before publishing.
- `docker-compose.hostinger.yml`: Docker Manager/Hostinger-oriented shape.
- `docker-compose.prod.yml`: GHCR/VPS shape with `db`, `redis`, `migrate` ops profile, `api`, `queue`, `scheduler`, `app`, and `nginx` services.
- `nginx/default.conf`: internal host routing between app/API and the external proxy network.
- `.github/workflows/docker-ghcr.yml`: image build/publish/deployment automation; inspect before changing release behavior.
- `docs/`: detailed source-of-truth runbooks. Update a relevant doc when this architecture changes.
- `_archive/`: recovery/reference only; not an active application source tree.

## Request flow

The preferred frontend mode is same-origin proxy mode. The browser calls `/api/...` on `app.insightistic.com`; Next.js forwards to the Laravel API using `API_PROXY_TARGET`. This keeps API origin, CORS, mixed-content, and build-time URL behavior centralized. Direct API mode is documented in `docs/INSTALL.md` and should only be used intentionally.

Authentication, tenant authorization, license validation, billing state, connector permissions, webhooks, and AI provider keys belong on the server side. Never move these secrets or access decisions into browser code.

## Deployment source of truth

Use the current repository files and `docs/VPS-GHCR-DEPLOYMENT.md` as the deployment source of truth. The remembered VPS commit/digest in `current-status.md` is a historical checkpoint, not an instruction to redeploy it blindly.

Before any release, reconcile:

- source commit and working-tree changes;
- GitHub Actions/GHCR image tag and digest;
- VPS `.env` values without copying them into this repository;
- compose file and pull policy;
- database migration/seed flags;
- container health and logs;
- Traefik network and HTTPS routing;
- app login, API health, and critical customer flow.

