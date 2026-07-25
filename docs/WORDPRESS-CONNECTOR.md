# WordPress Connector

The `insightistic` plugin (v1.1.0) connects a WordPress/WooCommerce site to
the SaaS. One Insightistic license activates both the plugin entitlements
and the connector — no separate connector license.

## Activation flow

1. Owner copies the license key (shown once at signup / in the trial email).
2. Plugin → `POST /api/plugin/activate` (public, throttled, key-authenticated).
3. Response carries one-time connector credentials `{key_id, secret}`;
   the plugin stores them (only the key's last-4 is kept).
4. All subsequent calls are HMAC-signed.

## HMAC contract (do not change — parity tested server-side)

```
canonical = METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256(body)
signature = hex hmac_sha256(canonical, secret)
headers:  X-INS-Key-Id, X-INS-Timestamp, X-INS-Nonce, X-INS-Signature
```

±300 s clock skew; per-site nonce replay cache (10 min);
constant-time compare. Covered by `tests/Feature/ConnectorHmacTest.php`.

## Sync engine (v1.1)

- **Daily incremental (delta) sync** — orders/products modified since the
  per-entity cursor (`date_modified` ascending, 50/page). A cursor only
  advances after the server confirms the page (checkpoint semantics).
- **Weekly full reconciliation** — the old full pass, now weekly; also
  fast-forwards cursors on completion. Customers sync on this pass.
- **Realtime events** — WooCommerce order/product hooks queue ids and a
  debounced Action Scheduler flush posts them in batches (≤50) through the
  same idempotent bulk endpoints.
- **Retry with backoff** — a failed page retries at +60 s/+5 m/+15 m before
  the chain advances (never wedges).
- **Batch ids** — every payload carries `sync_batch_id` for tracing.
- **Manual resync by date range** — from the plugin's License tab.
- **HPOS** — compatibility declared (`custom_order_tables`).

Endpoints used: `connector/v1/{ping,handshake,site-health,orders/bulk,
products/bulk,customers/bulk,sync-complete,content/bulk}` — all HMAC + rate
limited; server upserts are idempotent on natural keys.

## AI knowledge base content sharing (opt-in)

`Sync Settings → Share published pages, posts & product descriptions…`
(**default OFF**). When enabled the plugin pushes published content
(stripped of HTML, ≤100 k chars) to `connector/v1/content/bulk`; the server
indexes it into a site-scoped "Website content" knowledge base. Per-post
opt-out: set post meta `_insightistic_exclude`. Deletions propagate and
remove the indexed chunks. Plan limits apply server-side; skipped items are
logged in the plugin's Logs tab.

## Privacy notes

Customer emails are sha256-hashed on the store before leaving WordPress;
no billing addresses/phones are synced; content sharing is opt-in.
