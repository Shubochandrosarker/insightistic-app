# Insightistic — Install & Connect Guide

This guide takes a site owner from zero to a live dashboard. Three pieces:
**API** (Laravel), **App** (Next.js), **Connector** (WordPress plugin).

## 1. API (api.insightistic.com)
```bash
cd insightistic-api
composer install
composer require laravel/sanctum barryvdh/laravel-dompdf stripe/stripe-php laravel/socialite socialiteproviders/microsoft
cp .env.example .env        # set DB (Postgres), APP_URL, MAIL_*, STRIPE_*, INSIGHTISTIC_APP_URL, OPENAI_API_KEY (optional), OAuth keys (optional)
php artisan key:generate
php artisan migrate         # includes personal_access_tokens + oauth columns shipped in this repo
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
cp .env.local.example .env.local
npm install && npm run build && npm start
```

### How the app reaches the API (read this if signup shows "Failed to fetch")

The app supports two connection modes:

**Proxy mode (recommended, default).** Leave `NEXT_PUBLIC_API_URL` **empty**. The
browser calls the app's own origin (`/api/...`) and Next.js forwards those
requests server-side to Laravel. There is **no CORS, no mixed-content, and no
build-time URL baking** — which removes the three usual causes of *Failed to
fetch* on the register/login screens. Point the proxy at your API:

```env
NEXT_PUBLIC_API_URL=
API_PROXY_TARGET=http://127.0.0.1:8000   # PM2 single VPS
# API_PROXY_TARGET=http://api:8000        # Docker compose
# API_PROXY_TARGET=https://api.insightistic.com  # separate API host
```

> `API_PROXY_TARGET` is read by the **Next.js server at start-up**, so you can
> change it and `pm2 restart` without rebuilding.

**Direct mode.** Set `NEXT_PUBLIC_API_URL=https://api.insightistic.com`. The
browser then calls the API host directly. This requires the API to be reachable
over HTTPS *and* to send CORS headers for the app origin — both are handled by
`insightistic-api/config/cors.php` (set `CORS_ALLOWED_ORIGINS` to lock it down).
Remember: `NEXT_PUBLIC_*` is baked at **build time**, so you must
`npm run build` again after changing it.

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

## Social login (Google / Microsoft / GitHub)

Optional. Buttons appear on login/register **only** for providers you configure.
See **[docs/SOCIAL-LOGIN.md](SOCIAL-LOGIN.md)** for the full walkthrough. In short:
create an OAuth app at each provider, set the redirect URI to
`https://app.insightistic.com/api/auth/oauth/<provider>/callback`, and add the
`*_CLIENT_ID` / `*_CLIENT_SECRET` env vars to the API.

## Troubleshooting
- **"Failed to fetch" on Create account / Sign in** → this is a browser network
  error, not a server error. Causes & fixes:
  1. The app was built with `NEXT_PUBLIC_API_URL` pointing at an unreachable host
     (or left as `http://localhost:8000`). **Fix:** switch to proxy mode (leave it
     empty, set `API_PROXY_TARGET`) — see section 2 above.
  2. The page is HTTPS but the API URL is `http://` (mixed content blocked).
     **Fix:** use proxy mode, or serve the API over HTTPS.
  3. Direct mode without CORS / unreachable `api.` subdomain. **Fix:** proxy mode,
     or set `CORS_ALLOWED_ORIGINS` and confirm `https://api.insightistic.com` loads.
  Quick check: open the browser devtools **Network** tab and submit the form — a
  failed/blocked request (red, status `(failed)`/CORS) confirms a connection
  issue rather than bad credentials.
- **Test connection** (Connection tab) runs a read-only `connector/v1/ping` — it proves the
  HMAC signing chain without changing anything, and flags clock drift if the server time is off.
- **401 on connect** → token wrong or API behind a subfolder. Re-issue token in Settings.
- **"clock is too far off"** → the API server's time differs from the store by >5 min. Enable
  NTP / fix the server clock; signed requests are rejected outside a ±5-minute window.
- **No data after sync** → check WP **Logs** tab; ensure WooCommerce is active.
- **Logo not in PDF** → confirm `storage:link` ran and the logo URL is publicly reachable.
