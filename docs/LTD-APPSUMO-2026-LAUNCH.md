# Runbook — AppSumo 2026 LTD launch (Option A)

Status at merge: **implemented and disabled**. Nothing in this runbook has been
executed against Paddle; every step below is manual and launch-time only.

The campaign (`appsumo_2026`) ships dormant: `FEATURE_LTD_APPSUMO=false`, the
three Paddle price env vars are empty, and no `plan_prices` rows exist. The
direct-from-site Founding Angel offer (`founding-angel-2026`,
`pri_01m4wn26nhwwr3xpn4tdgfdss3` / `pri_01m4wn2791zs00kbhw2w3w36ma` /
`pri_01m4wn27vn7hrbfxbhk336nnw9`) is untouched.

## Locked deal terms (founder, 2026-11-07)

| Tier | Plan slug | Price | Sites | AI allowance (plan-based, NOT credits) |
|---|---|---:|---:|---|
| AppSumo Solo | `appsumo_solo` | $79 | 3 | 200 standard AI requests/mo (Growth level) |
| AppSumo Studio | `appsumo_studio` | $149 | 10 | 600 standard AI requests/mo (Agency level) |
| AppSumo Agency | `appsumo_agency` | $269 | 25 | 600 standard AI requests/mo (Agency level) |

- `monthly_ai_credits` is **0** on all three plans — AppSumo buyers never
  receive AI credits (no monthly grant; they may still buy credit packs).
- Campaign cap: 500 sales (`commercial.appsumo_campaign.maximum_sales`, doc 33
  scarcity ceiling). **Set the final cap before the first sale** — the value is
  read once when the `ltd_campaigns` row is created; after that, update the row
  directly (see "Post-launch verification").

## Step 1 — create the Paddle catalog (manual, sandbox first)

Base URLs: sandbox `https://sandbox-api.paddle.com`, live
`https://api.paddle.com`. Auth header: `Authorization: Bearer $PADDLE_API_KEY`
(requires `product.write` + `price.write`). Field names follow the Paddle
Billing API (developer.paddle.com, "Create products and prices").

**Note on "draft":** Paddle Billing has no `draft` status for products/prices —
the only statuses are `active` and `archived` (per the Paddle OpenAPI spec).
Created entities are immediately active, so step 1.3 archives them as the
real-world "draft": archived prices cannot be used in any checkout.

### 1.1 Create the product (do NOT reuse the Founding Angel product)

```bash
curl -X POST "https://sandbox-api.paddle.com/products" \
  -H "Authorization: Bearer $PADDLE_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Insightistic LTD — AppSumo 2026",
    "tax_category": "standard",
    "description": "AppSumo-exclusive lifetime deal tiers (Option A): Solo/Studio/Agency, site-count entitlements, plan-based AI allowance.",
    "custom_data": {"campaign": "appsumo_2026", "product_line": "insightistic_ltd"}
  }'
```

Keep the `pro_...` id from `data.id`.

### 1.2 Create the three one-time prices

One-time price = `billing_cycle: null` with `quantity` locked to 1 (Paddle's
one-time-charge pattern). `custom_data` mirrors what the app puts on checkout
custom_data so support can trace any transaction back to the campaign.

```bash
curl -X POST "https://sandbox-api.paddle.com/prices" \
  -H "Authorization: Bearer $PADDLE_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": "pro_XXXXXXXX",
    "description": "AppSumo Solo — lifetime (3 sites)",
    "billing_cycle": null,
    "unit_price": {"amount": "7900", "currency_code": "USD"},
    "quantity": {"minimum": 1, "maximum": 1},
    "custom_data": {"campaign": "appsumo_2026", "plan_slug": "appsumo_solo", "purchase_kind": "lifetime", "product_line": "insightistic_ltd"}
  }'
```

Repeat twice more:

| description | unit_price | custom_data.plan_slug |
|---|---|---|
| `AppSumo Studio — lifetime (10 sites)` | `"14900"` | `appsumo_studio` |
| `AppSumo Agency — lifetime (25 sites)` | `"26900"` | `appsumo_agency` |

Keep the three `pri_...` ids.

### 1.3 Hold in "draft" (archive until launch)

```bash
curl -X PATCH "https://sandbox-api.paddle.com/prices/pri_XXXXXXXX" \
  -H "Authorization: Bearer $PADDLE_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"status": "archived"}'
```

Optionally archive the product the same way (`PATCH /products/pro_...`).
Verify each entity: `GET /prices/pri_...` → `status: "archived"`.

## Step 2 — map the price IDs (env + seeder)

`.env` (production, after sandbox verification):

```text
PADDLE_PRICE_LTD_APPSUMO_SOLO=pri_XXXXXXXX
PADDLE_PRICE_LTD_APPSUMO_STUDIO=pri_XXXXXXXX
PADDLE_PRICE_LTD_APPSUMO_AGENCY=pri_XXXXXXXX
FEATURE_LTD_APPSUMO=false   # stays false until step 4
```

Then seed (both are idempotent upserts; re-runnable):

```bash
php artisan db:seed --class=PlanSeeder          # creates the hidden appsumo_* plans
php artisan db:seed --class=PaddleCatalogSeeder # maps the three price IDs to plan_prices
```

Verify locally (DB):

- `select provider_price_id, plan_id, kind, amount_minor from plan_prices where metadata_json->>'campaign' = 'appsumo_2026';` → 3 rows, kind `lifetime`, amounts 7900/14900/26900.
- `select slug, site_limit, limits_json->>'standard_ai_requests_monthly', limits_json->>'monthly_ai_credits' from plans where slug like 'appsumo_%';` → sites 3/10/25, AI 200/600/600, credits 0.
- No `ltd_campaigns` row for `appsumo_2026` yet — it is created lazily at the first reservation/sale.

Even with prices mapped, checkout stays impossible while
`FEATURE_LTD_APPSUMO=false` (`invalid_ltd_plan`) — covered by
`PaddleCheckoutConfigTest`.

## Step 3 — AppSumo-side setup (manual, no code)

- AppSumo listing copy, pricing, refund policy (30-day money-back matches
  `commercial.refund_window_days`), and the redemption/fulfilment arrangement.
- Point AppSumo checkout at the three prices only after step 4 flips them
  active. Orders that reach Paddle WITHOUT a local reservation are held for
  manual reconciliation (transaction `summary_json.ltd_reconciliation.status`
  = `manual_review`) — reconcile via Admin → Billing, or grant manually.

## Step 4 — go live (launch day, in order)

1. Activate the catalog in Paddle:
   `PATCH /prices/pri_... {"status": "active"}` ×3 (and the product).
2. Set `FEATURE_LTD_APPSUMO=true` in the server environment, then deploy /
   `php artisan config:clear` (config is cached in production).
3. Smoke-check: `POST /api/billing/checkout-config {plan: "appsumo_solo", kind: "lifetime"}`
   as an org owner returns a price id + `ltd_reservation_id`.
4. Publish/enable the AppSumo listing.

## Post-launch verification & monitoring

- Campaign inventory: `php artisan tinker` →
  `\App\Models\LtdCampaign::where('slug','appsumo_2026')->first();` —
  `successful_sales`, `maximum_sales`, `state`. To change the cap after the
  row exists: update the row (`maximum_sales`) — config edits alone do not
  propagate to an existing campaign row.
- Each sale: `BillingTransaction` (kind `lifetime`) → `EntitlementGrant`
  (source `paddle_lifetime`, plan = the appsumo tier) → resolved limits via
  `EntitlementResolver` (sites 3/10/25, standard AI 200/600/600, credits 0).
- Founding Angel isolation: `appsumo_2026` sales never increment the
  `founding-angel-2026` campaign (and vice versa) — covered by
  `PaddleWebhookTest`.

## Rollback / close-out

- Pause: `FEATURE_LTD_APPSUMO=false` (stops new checkouts immediately;
  existing grants and reservations are unaffected) and/or archive the prices.
- Close-out: the campaign row auto-closes at the cap; remove the flag from
  `config/feature-flags.php` when the campaign ends (per its removal note).
- Refunds: Paddle full adjustments revoke the lifetime grant
  (`PaddleTransactionProjector::processAdjustment`).
