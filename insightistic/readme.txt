=== Insightistic ===
Contributors: wordpressistic
Tags: woocommerce, analytics, reports, ai, license
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later

AI-powered WooCommerce analytics for Insightistic — activate with your license key, no manual tokens.

== Description ==
Insightistic is the main plugin for the Insightistic SaaS. Activate it with the
license key from your Insightistic dashboard (Dashboard → Licenses) and it
takes care of the rest: it exchanges the key for per-site HMAC connector
credentials, then syncs WooCommerce orders, products, customers and site
health, and checks in daily to keep your plan's entitlements (AI insights,
advanced reports, white-label, etc.) up to date. It only sends business data —
never card data or passwords — and every sync request is HMAC-signed.

**Prefer not to install a licensed plugin, or managing a client's site as an
agency?** Use the lighter [Insightistic Connector](https://insightistic.com)
plugin instead — it syncs the same WooCommerce data via a manually-issued
connector token (Dashboard → Settings → Add a site) without any license
activation.

== Installation ==
1. Install and activate the plugin.
2. Copy your license key from the Insightistic dashboard (Dashboard → Licenses
   — shown once right after signup, or via "Regenerate key").
3. Go to WordPress Admin → Insightistic → License, paste the key, click Activate.
4. Your site appears on the Licenses page automatically and the first sync
   starts immediately. A daily incremental sync, a weekly full reconciliation
   and daily license check-ins run via Action Scheduler after that.

== Changelog ==
= 1.1.0 =
* Incremental daily sync: only orders/products modified since the last
  checkpoint are sent (per-entity cursors that advance only after a page is
  confirmed received). The full historical sync now runs as a weekly
  reconciliation instead of daily.
* Realtime updates: order and product changes (create/update/status/refund/
  stock) are batched and pushed about a minute after they happen.
* Retry with backoff (1m/5m/15m) for failed sync pages — a temporary API
  outage no longer stalls or wedges the sync chain.
* Every bulk payload now carries a sync_batch_id (uuid per run) so
  server-side upserts are idempotent.
* HPOS: declared compatibility with WooCommerce High-Performance Order
  Storage (custom order tables).
* NEW, opt-in (off by default): share published pages, posts and product
  descriptions with the Insightistic AI Knowledge Base. Password-protected,
  private and draft content is never sent; add the `_insightistic_exclude`
  post meta to exclude individual posts. Deletions are propagated.
* Manual resync by date range: optionally limit a manual sync to orders
  created between two dates (License screen).

= 1.0.0 =
* Initial release: license-key activation (spec §4), HMAC connector sync
  (orders/products/customers/site-health), daily license validation.
