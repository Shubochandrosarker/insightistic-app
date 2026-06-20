# Insightistic App (Next.js frontend)

Marketing site + multi-tenant dashboard for the Insightistic API.

## Stack
Next.js 15 (App Router) · React 19 · Tailwind CSS 3 · Recharts · lucide-react.
Token auth (Sanctum bearer) stored in `localStorage`. No external state library.

## Run
```bash
cp .env.local.example .env.local      # set NEXT_PUBLIC_API_URL to your API
npm install
npm run dev                            # http://localhost:3000
```
`NEXT_PUBLIC_API_URL` must point at the Laravel API root (e.g. `http://localhost:8000`
in dev, `https://api.insightistic.com` in prod). The client calls `${API_URL}/api/...`.

## Routes
**Marketing:** `/` (landing), `/pricing`, `/terms`, `/privacy`
**Auth:** `/login`, `/register`, `/forgot-password`, `/reset-password`, `/accept-invite`
**Dashboard** (`/dashboard`, auth-guarded): Overview, AI Insights, Revenue, Products,
Customers, Reports, Billing, White Label, Team, Settings.

## How it's wired
- `lib/api.ts` — fetch client (bearer token, JSON + multipart helpers).
- `lib/auth.tsx` — `AuthProvider` / `useAuth` (login, register, logout, `/auth/me`).
- `lib/dashboard.tsx` — `DashboardProvider` / `useDashboard` (sites, current site, period).
- `lib/useApi.ts` — generic GET hook with `reload()`.
- Every dashboard page is a client component using `useDashboard()` (site + period) and
  `useApi()` against the documented API endpoints. Use the Overview page as the pattern
  for any new page.

## Before launch
- Replace the **Terms** and **Privacy** templates with counsel-reviewed copy.
- Set Stripe price IDs on the API and confirm checkout/portal redirect URLs use this app's domain.
- This frontend is code-complete; run `npm run build` once to catch any type/env issues in your environment.
