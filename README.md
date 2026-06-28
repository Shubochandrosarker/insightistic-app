# Insightistic

Insightistic is a multi-tenant WooCommerce analytics SaaS package with three deployable parts:

- `insightistic-app/` - Next.js frontend for marketing, auth, and dashboard.
- `insightistic-api/` - Laravel API overlay files. Scaffold a fresh Laravel app first, then copy these files over it.
- `insightistic-connector/` - WordPress connector plugin for WooCommerce sync.
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

## Production Shape

- Frontend: `https://app.insightistic.com`
- API: `https://api.insightistic.com`
- WordPress plugin: zip and upload `insightistic-connector/` to client WooCommerce stores.

Keep API keys, database credentials, Stripe secrets, OpenAI keys, and connector tokens out of Git. Use VPS environment files only.
