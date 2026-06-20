# Insightistic

Insightistic is a multi-tenant WooCommerce analytics SaaS package with three deployable parts:

- `insightistic-app/` - Next.js frontend for marketing, auth, and dashboard.
- `insightistic-api/` - Laravel API overlay files. Scaffold a fresh Laravel app first, then copy these files over it.
- `insightistic-connector/` - WordPress connector plugin for WooCommerce sync.
- `docs/` - install, launch, and VPS deployment runbooks.

## Quick Start

```bash
cd insightistic-app
cp .env.local.example .env.local
npm install
npm run build
npm start
```

For the backend, follow `insightistic-api/README.md` or `docs/HOSTINGER-VPS.md`. The API folder intentionally does not include Laravel core or `vendor/`; those are generated on the server with Composer.

## Production Shape

- Frontend: `https://app.insightistic.com`
- API: `https://api.insightistic.com`
- WordPress plugin: zip and upload `insightistic-connector/` to client WooCommerce stores.

Keep API keys, database credentials, Stripe secrets, OpenAI keys, and connector tokens out of Git. Use VPS environment files only.
