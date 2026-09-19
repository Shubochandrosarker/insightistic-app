# Reports, Agency Workspace & White Label

## Agency product goal

Allow one operator to monitor many WordPress sites without opening each project individually.

## Portfolio dashboard

Columns/cards:

- site
- health score
- search trend
- critical issues
- uptime
- data freshness
- AI visibility trend
- next scheduled report

Filters:

- client
- health state
- integration status
- plan/tag
- assigned team member

## Client grouping

Add optional client/account entity if organizations need multiple sites per client.

```text
organization
  -> clients
      -> sites
```

Do not force client grouping on single-site users.

## Reports

### Report types

- executive monthly
- SEO/search
- technical audit
- performance
- WooCommerce
- AI visibility
- custom agency template

### Report sections

Composable section system:

```text
summary
KPI cards
search trend
opportunities
crawl health
performance
traffic
AI visibility
commerce
completed actions
next actions
```

## Scheduled reports

Configuration:

- site/client
- frequency
- timezone
- recipients
- format
- template
- branding

Generation and email delivery are queued.

## White label

Agency plan feature:

- logo
- company name
- accent color
- report footer
- sender name where email infrastructure permits

Agency Pro:

- custom report domain / application domain if operationally supported

## Share links

Provide revocable, tokenized, read-only report links with:

- expiry option
- optional password
- no access to live dashboard unless explicitly granted

Never expose sequential report IDs without authorization.

## Client viewer

Client viewer sees only assigned sites and approved sections.

Agency owner can disable sensitive commerce/customer details.

## Acceptance criteria

- portfolio aggregates are precomputed/cached for performance
- white label never overrides system security notices
- report links are revocable and unguessable
- scheduled reports honor tenant timezone
- report generation failures are retryable and visible
