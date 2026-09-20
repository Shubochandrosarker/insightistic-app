# WooCommerce Intelligence

## Goal

Use WooCommerce first-party data as Insightistic's business-impact layer, not as the sole product identity.

## Data ingestion

Current connector patterns already sync orders, products and customers. Extend with incremental events and reconciliation.

### Order fields

At minimum:

- external order ID
- number
- status
- currency
- totals
- refund amount
- timestamps
- payment method label
- item lines

Never ingest payment card details or gateway secrets.

### Product fields

- external ID
- name
- SKU
- price
- sale price
- stock
- status
- category mappings when useful

### Customer privacy

Prefer hashed email for analytical identity where direct contact identity is not required.

## Metrics

- gross/net revenue definition documented
- paid order count
- AOV
- refunds/refund rate
- new vs returning customers
- repeat purchase rate
- units sold
- top products/categories
- low stock
- product mix concentration

## Revenue computation

Do not blindly trust cumulative WooCommerce product/customer counters for analytical windows. Recompute from eligible order rows according to explicit order-status rules.

## Intelligence

### RFM / customer segments

Use site-relative quantiles and minimum sample sizes.

### Churn risk

Base on each customer's observed purchase cadence where enough history exists. Do not apply one global "30 days = churn" rule to all businesses.

### Stock cover

Estimate days/weeks of stock based on recent units/day with clear caveats.

### Product affinity

Require minimum co-occurrence counts before showing associations.

## Search-to-revenue analysis

High-value feature:

- GSC landing page visibility
- GA4 landing page behavior
- WooCommerce revenue/orders
- crawl/CWV health

Examples:

> "This category page lost 21% organic clicks while its conversion rate stayed stable. Recovering traffic is likely more important than changing checkout."

> "This product page has strong impressions and revenue but fails CWV and has a weak title."

Causality must not be overstated.

## Access control

Individual customer-level reports should be restricted from `client_viewer` where privacy requires it.

## UI

```text
Commerce
├── Overview
├── Orders
├── Products
├── Customers
├── Inventory
└── Opportunities
```

## Acceptance criteria

- duplicate connector retries do not duplicate orders
- refunds update historical metrics correctly
- currency handling is site-aware
- commerce panels appear only when WooCommerce is active
- customer PII is minimized
