# Insightistic — Install & Connect Guide

This guide takes a site owner from zero to a live dashboard. Three pieces:
**API** (Laravel), **App** (Next.js), **Connector** (WordPress plugin).

## 1. API (api.insightistic.com)
```bash
cd insightistic-api
composer install
composer require barryvdh/laravel-dompdf stripe/stripe-php
cp .env.example .env        # set DB (Postgres), APP_URL, MAIL_*, STRIPE_*, INSIGHTISTIC_APP_URL, OPENAI_API_KEY (optional)
php artisan key:generate
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\PlanSeeder
php artisan storage:link
# optional demo data:
php artisan db:seed --class=Database\\Seeders\\DemoSeeder
php artisan serve
```
> Host the API at a **domain root** (not a subfolder) — the connector's HMAC signs the path.
> Schedule snapshots: add `* * * * * php artisan schedule:run` (runs nightly `insightistic:snapshots`).

## 2. App (app.insightistic.com)
```bash
cd insightistic-app
cp .env.local.example .env.local   # NEXT_PUBLIC_API_URL=https://api.insightistic.com
npm install && npm run build && npm start
```

## 3. Connect a WooCommerce store
1. In the dashboard → **Settings → Add a site**. Copy the one-time **connector token**.
2. On the WordPress site: install **insightistic-connector** (Plugins → Add New → Upload),
   activate it.
3. Go to **Insightistic** in the WP admin menu → **Connection** tab → paste the API URL
   (`https://api.insightistic.com`) and the connector token → **Connect**.
4. **Sync** tab → **Run first sync**. Orders, products and customers import in chunks
   (Action Scheduler). Customer emails are hashed on the store; no card data is ever sent.
5. Back in the dashboard, your **Overview** fills with revenue, customers, products, and
   you can generate AI insights and reports.

## Roles
Owner (billing/everything) · Admin (sites, reports, invite viewers) · Analyst (view + reports)
· Client viewer (read-only, only assigned sites). Invite from **Team**.

## Troubleshooting
- **Test connection** (Connection tab) runs a read-only `connector/v1/ping` — it proves the
  HMAC signing chain without changing anything, and flags clock drift if the server time is off.
- **401 on connect** → token wrong or API behind a subfolder. Re-issue token in Settings.
- **"clock is too far off"** → the API server's time differs from the store by >5 min. Enable
  NTP / fix the server clock; signed requests are rejected outside a ±5-minute window.
- **No data after sync** → check WP **Logs** tab; ensure WooCommerce is active.
- **Logo not in PDF** → confirm `storage:link` ran and the logo URL is publicly reachable.
