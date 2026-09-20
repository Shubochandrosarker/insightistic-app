# UI/UX & Design System

## Design direction

Target feel:

**Linear clarity + Stripe dashboard discipline + Screpy density**, without cloning any product.

## Information architecture

Recommended desktop navigation:

```text
Overview

Search Intelligence
  Search Performance
  Rank Tracker
  Index Coverage
  Opportunities
  Bing Webmaster

AI Visibility
  Overview
  Prompts
  Mentions
  Citations
  Competitors

Site Audit
  Overview
  Pages
  Links
  Images
  Issues
  Changes

Performance
  Core Web Vitals
  PageSpeed
  Uptime
  Cloudflare

Traffic
  Analytics
  Acquisition
  Landing Pages
  AI Referrals

Commerce (conditional)
  Overview
  Orders
  Products
  Customers

Intelligence
  Insights
  Copilot
  Alerts
  Automations

Reports

Settings
  Sites
  Integrations
  Team
  Branding
  Billing
  License
  Security
  Privacy
```

## Global controls

Topbar:

- organization switcher if needed
- project/site switcher
- date range
- comparison period
- command palette
- notifications
- user menu

Do not repeat project/date controls independently on every page.

## Universal Overview

Top row:

- Site Health
- Organic Clicks
- Search Impressions
- Avg Position
- AI Visibility
- CWV Pass
- Uptime

Below:

1. search trend
2. priority actions
3. technical health
4. AI visibility
5. edge/traffic
6. commerce only when relevant

## Component rules

### Cards

- radius 10–12px
- subtle border
- minimal/no shadow
- compact header
- consistent inner padding

### KPI cards

Must support:

- current value
- comparison
- trend sparkline
- data source tooltip
- stale indicator

### Tables

SEO products are table-heavy. Build one robust data-table system with:

- server pagination
- sorting
- filtering
- column visibility
- sticky header
- row actions
- export
- saved views later

### Charts

- prioritize readability over decorative gradients
- consistent date axes
- clearly distinguish compared period
- tooltips include exact values
- never encode critical state by color only

## State system

Every data panel supports:

```text
loading
empty-not-connected
empty-no-data-yet
empty-no-findings
stale
degraded
error
ready
```

These are different states and need different copy/actions.

## Empty-state examples

### Integration not connected

> Connect Google Search Console to see query and page performance.

CTA: `Connect Search Console`

### Connected but historical sync pending

> Search Console is connected. We are importing your history now.

CTA: none / progress

### No issues found

> No critical indexability issues found in the latest crawl.

Do not show a generic blank page.

## Visual tokens

Recommended starting values:

```text
Sidebar: 232px
Page padding desktop: 24px
Card radius: 10–12px
Input/button radius: 8–10px
Base font: Geist or Inter
Body: 14px
Table: 13px
Muted text: accessible AA contrast
```

Use one primary accent and semantic status colors sparingly.

## Responsive behavior

Desktop is primary for deep SEO tables, but mobile must support:

- overview KPIs
- alerts
- basic charts
- quick project switching
- report viewing

Large tables can use horizontal scrolling or condensed cards.

## Accessibility

- keyboard navigation
- visible focus
- semantic headings
- chart summaries where practical
- no color-only severity
- reduced-motion support
- accessible form labels/error messages

## UI acceptance criteria

- no more than one primary site/date context per screen
- grouped navigation reduces cognitive load
- pages use consistent loading/error/empty patterns
- billing/usage states are clear
- source freshness is visible when it affects interpretation
