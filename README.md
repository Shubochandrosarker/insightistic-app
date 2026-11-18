# Insightistic

Insightistic is a multi-tenant WooCommerce analytics SaaS. This repo holds the two
deployable parts of the **app** (dashboard + API) — WordPress-side code lives in
sibling repos, kept fully separate so each ships and versions independently:

> **Agent and maintainer entry point:** read [`claude.md`](./claude.md), then
> [`Memory/README.md`](./Memory/README.md) and the current status before making
> code, deployment, or live-site changes.

- `insightistic-app/` - Next.js frontend for auth and the customer dashboard
  (incl. the AI Copilot and Knowledge pages).
- `insightistic-api/` - complete Laravel 12 application (committed
  composer.json/composer.lock, tests). `composer install && php artisan test`
  works from a plain checkout — see `docs/TESTING.md`.
- `insightistic/` - the WordPress/WooCommerce connector & licensing plugin.
- `docs/` - architecture, AI knowledge base/security, environment variables,
  testing, privacy, install/launch/deployment runbooks.

WordPress-side code (not in this repo):

- [`insightistic`](https://github.com/Shubochandrosarker/insightistic) - the canonical WordPress plugin: activates with a license key (`/api/plugin/*`) and syncs WooCommerce, GA4, Search Console, PageSpeed, and Cloudflare data. This is the plugin the dashboard's Licenses page (`Dashboard → Licenses`) walks users through installing.
- [`insightistic-website`](https://github.com/Shubochandrosarker/insightistic-website) - the public marketing site (`insightistic-marketing/` theme + `formistic/` lead-capture forms) and the legacy `insightistic-connector/` plugin (manual connector-token flow for sites that don't run the main plugin — see `docs/INSTALL.md` §3).

## Quick Start

```bash
cd insightistic-app
cp .env.local.example .env.local   # leave NEXT_PUBLIC_API_URL empty, set API_PROXY_TARGET
npm install
npm run build
npm start
```

For the backend: `cd insightistic-api && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate --seed`. Requires PostgreSQL 16 (pgvector recommended) and Redis — see `docs/ENVIRONMENT-VARIABLES.md`.

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
- WordPress plugin: zip and upload the main plugin from the [`insightistic`](https://github.com/Shubochandrosarker/insightistic) repo (license-key activation) or the connector from the [`insightistic-website`](https://github.com/Shubochandrosarker/insightistic-website) repo (manual connector token) depending on the customer's flow.

Keep API keys, database credentials, Stripe secrets, OpenAI keys, and connector tokens out of Git. Use VPS environment files only.
