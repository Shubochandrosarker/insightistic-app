=== Insightistic Connector ===
Contributors: wordpressistic
Tags: woocommerce, analytics, reports, ai, dashboard
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Securely syncs WooCommerce orders, products, customers and site health to the
Insightistic SaaS for AI-powered business analytics and reports.

== Description ==
Insightistic Connector links your WordPress/WooCommerce store to your Insightistic
dashboard. It only sends business data (orders, products, customers, site health) —
never card data or passwords. All requests are HMAC-signed; the secret never leaves
your server in cleartext.

== Installation ==
1. Install and activate the plugin.
2. In your Insightistic dashboard, add a site and copy the one-time connector token.
3. Go to Insightistic → Connection, paste the token + SaaS URL, click Connect Site.
4. Use "Run Manual Sync" for the first sync. Daily auto-sync runs via Action Scheduler.

== Changelog ==
= 0.1.0 =
* Initial connector: HMAC handshake + chunked orders/products/customers/site-health sync.
