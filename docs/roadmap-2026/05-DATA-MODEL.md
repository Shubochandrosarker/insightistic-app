# Data Model Specification

## Goals

The database must represent:

- organizations and users
- sites and memberships
- billing/entitlements
- provider integrations
- WordPress/WooCommerce data
- search observations
- crawl observations
- rank observations
- AI visibility observations
- performance/uptime
- alerts, insights and reports

## Core tenancy entities

### `organizations`

Key fields:

```text
id
name
slug
status
plan_id
trial_ends_at
timezone
created_at
updated_at
```

### `organization_users`

```text
organization_id
user_id
role
joined_at
```

Roles:

```text
owner
admin
analyst
client_viewer
```

### `sites`

```text
id
organization_id
name
url
normalized_host
timezone
currency
wordpress_detected
woocommerce_active
last_sync_at
status
created_at
updated_at
```

Add provider-specific fields to dedicated integration tables instead of continuously expanding `sites`.

## Billing and plan model

### `plans`

Keep product entitlements independent of payment provider.

```text
id
slug
name
is_active
is_featured
site_limit
user_limit
features_json
limits_json
sort_order
```

Do not make Paddle price IDs the primary plan definition.

### `plan_prices`

New recommended table:

```text
id
plan_id
provider                # paddle
kind                    # monthly|yearly|lifetime|credit_pack
provider_product_id
provider_price_id
currency
amount_minor
billing_interval
is_active
metadata_json
created_at
updated_at
```

### `subscriptions`

Refactor to provider-neutral fields:

```text
id
organization_id
billing_provider          # paddle
provider_customer_id
provider_subscription_id
provider_price_id
status                    # local normalized status
provider_status
billing_interval
current_period_start
current_period_end
next_billed_at
scheduled_change_action
scheduled_change_at
canceled_at
created_at
updated_at
```

### `billing_transactions`

```text
id
organization_id
provider
provider_transaction_id
provider_subscription_id nullable
provider_price_id nullable
kind                      # subscription|lifetime|credits|adjustment
status
currency
subtotal_minor
tax_minor
total_minor
invoice_number nullable
occurred_at
completed_at nullable
raw_summary_json nullable
created_at
updated_at
```

### `billing_events`

```text
id
provider
provider_event_id UNIQUE
provider_notification_id nullable
event_type
occurred_at
payload_hash
processing_status
processed_at
last_error
attempts
created_at
```

Payload retention should be privacy-conscious. Full webhook JSON can be stored temporarily or in encrypted/controlled storage if operationally needed; a durable normalized projection is preferred.

### Lifetime entitlements

Use a durable grant table rather than pretending a one-time purchase is a subscription.

`entitlement_grants`:

```text
id
organization_id
source                    # paddle_lifetime|manual|promotion
source_reference
plan_id
granted_features_json
limits_json
starts_at
ends_at nullable           # null = permanent grant
revoked_at nullable
created_at
```

The entitlement resolver combines subscription state, lifetime grants and admin overrides according to explicit precedence.

## Integration model

### `data_source_connections`

```text
id
organization_id
site_id
source
state
account_label
external_account_id
external_resource_id
credentials_encrypted
scopes_json
last_successful_at
latest_data_at
last_error_code
last_error_message
metadata_json
created_at
updated_at
```

## Search model

### `seo_daily`

```text
organization_id
site_id
source                    # gsc|bing
date
clicks
impressions
ctr
avg_position
```

Unique key: `(site_id, source, date)`.

### `seo_query_snapshots`

```text
site_id
source
date
query
page nullable
country nullable
device nullable
search_appearance nullable
clicks
impressions
ctr
position
```

High-volume table; design indexes around site/date and query/page exploration.

### `seo_page_snapshots`

Normalized page-focused aggregates when needed for fast page reports.

## Crawler model

### `crawl_runs`

```text
id
organization_id
site_id
status
trigger
started_at
completed_at
max_pages
pages_discovered
pages_crawled
issues_found
config_json
error_summary
```

### `crawl_pages`

Represents current/latest normalized page state.

```text
site_id
url_hash
url
status_code
indexable
canonical_url
title
meta_description
h1
word_count
content_hash
schema_types_json
robots_json
response_ms
size_bytes
last_crawled_at
```

### `crawl_page_observations`

Optional historical snapshots for change detection. Keep only fields that actually need history.

### `crawl_links`

```text
run_id
source_page_id
target_url_hash
target_url
is_internal
rel_json
anchor_text_hash / normalized anchor when needed
status_code nullable
```

### `crawl_issues`

```text
run_id
site_id
page_id nullable
issue_code
severity
category
fingerprint
status            # open|resolved|ignored
first_seen_at
last_seen_at
resolved_at
metadata_json
```

Fingerprint lets the system track issue lifecycle across runs.

## Rank tracking model

### `rank_keywords`

```text
organization_id
site_id
keyword
country
language
device
search_engine
active
created_at
```

### `rank_observations`

```text
keyword_id
date
position nullable
url nullable
serp_features_json
provider
raw_cost_units
```

## AI visibility model

### `ai_prompts`

```text
organization_id
site_id
prompt
locale
category
active
schedule
```

### `ai_prompt_runs`

```text
prompt_id
provider
model_or_surface
run_at
response_hash
brand_mentioned
citation_count
cost_units
status
```

### `ai_mentions`

```text
prompt_run_id
entity_name
entity_type             # brand|competitor
position_order nullable
sentiment nullable
context_excerpt_safe nullable
```

### `ai_citations`

```text
prompt_run_id
url
normalized_host
is_owned_domain
citation_order
```

## Performance and uptime

Tables should retain historical observations rather than only latest values:

- `performance_runs`
- `performance_issues`
- `uptime_monitors`
- `uptime_checks`
- `uptime_incidents`

## Analytics principle

Store raw-enough normalized observations. Derive business intelligence from them using tested services. Do not duplicate every derived metric into permanent columns unless there is a measured performance reason.
