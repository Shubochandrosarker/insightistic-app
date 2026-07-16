# Insightistic

Insightistic is a multi-tenant WooCommerce analytics SaaS package with four deployable parts:

- `insightistic-app/` - Next.js frontend for marketing, auth, and dashboard.
- `insightistic-api/` - Laravel API overlay files. Scaffold a fresh Laravel app first, then copy these files over it.
- `insightistic/` - Main WordPress plugin: activates with a license key (`/api/plugin/*`) and syncs WooCommerce data. This is the plugin the dashboard's Licenses page (`Dashboard → Licenses`) walks users through installing.
- `insightistic-connector/` - Lightweight WordPress connector plugin for sites that don't run the main plugin (e.g. an agency dashboarding a client's store); connects via a manually-issued connector token instead of a license key (`Dashboard → Settings → Add a site`).
- `docs/` - install, launch, and VPS deployment runbooks.

## Quick Start

```bash
cd insightistic-app
cp .env.local.example .env.local   # leave NEXT_PUBLIC_API_URL empty, set API_PROXY_TARGET
npm install
npm run build
npm start
```

For the backend, follow `insightistic-api/README.md` or `docs/HOSTINGER-VPS.md`. The API folder intentionally does not include Laravel core or `vendor/`; those are generated on the server with Composer.

## API connection (avoids "Failed to fetch")

By default the app runs in **proxy mode**: the browser only ever calls the app's
own origin (`/api/...`) and Next.js forwards requests to Laravel. This removes
CORS, mixed-content, and build-time URL issues — the common causes of *Failed to
fetch* on signup. Set `API_PROXY_TARGET` to your API (`http://127.0.0.1:8000`,
`http://api:8000`, or a full URL). See `docs/INSTALL.md` for direct mode.

## Social login

Optional Google / Microsoft / GitHub sign-in. Buttons show only for configured
providers — see `docs/SOCIAL-LOGIN.md`.

## Docker / Hostinger

Use `docker-compose.hostinger.yml` in Hostinger Docker Manager. Set the values from `.env.docker.example` in the Docker Manager environment panel before deploying, especially `APP_KEY`, `DB_PASSWORD`, mail settings, Stripe keys, and `OPENAI_API_KEY` if you want OpenAI instead of the built-in rule provider.

For a VPS that already runs Traefik and multiple projects, use the GHCR-based
production kit instead:

- GitHub Actions workflow: `.github/workflows/docker-ghcr.yml`
- VPS compose file: `docker-compose.prod.yml`
- VPS env template: `.env.production.example`
- Internal nginx routing: `nginx/default.conf`
- Full runbook: `docs/VPS-GHCR-DEPLOYMENT.md`

The production compose file does not bind host port 80. Traefik routes
`app.insightistic.com` and `api.insightistic.com` to the internal nginx service
over the shared Docker network.

## Production Shape

- Frontend: `https://app.insightistic.com`
- API: `https://api.insightistic.com`
- WordPress plugin: zip and upload `insightistic/` (license-key activation) or `insightistic-connector/` (manual connector token) depending on the customer's flow.

Keep API keys, database credentials, Stripe secrets, OpenAI keys, and connector tokens out of Git. Use VPS environment files only.
