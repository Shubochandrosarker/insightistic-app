# Bing Webmaster & IndexNow

## Goal

Add Bing as a first-class search source rather than treating Google as the entire search ecosystem.

## Scope

Use the current Bing Webmaster REST API, not retired legacy SOAP/POX interfaces.

Target data/features:

- site verification/connection
- search performance
- keyword/query data
- page/URL data
- crawl/index issues where API supports them
- sitemap operations where useful
- backlink/link data where API supports it
- URL submission
- IndexNow integration

## Architecture

Create:

```text
BingWebmasterClient
BingConnectionService
SyncBingSearchDataJob
BingSearchNormalizer
IndexNowService
```

Expose normalized search rows using `source = bing` so shared charts can compare providers without duplicating the whole UI stack.

## Connection flow

1. user opens Integrations
2. chooses Bing Webmaster
3. authenticates/enters provider credential as required by current API
4. SaaS lists accessible sites
5. user maps a Bing site to an Insightistic site
6. server verifies access
7. historical sync queued
8. integration marked connected after first successful verification

## Search Intelligence UI

Add source filter:

```text
Google
Bing
Combined
```

Do not combine average position by naive arithmetic. Combined views should clearly define aggregation methodology.

## IndexNow

### Purpose

When supported, submit changed URLs to participating engines after meaningful WordPress content changes.

### Plugin workflow

1. post/product published or updated
2. determine canonical public URL
3. deduplicate submissions with short lock
4. enqueue submission
5. call SaaS or direct IndexNow endpoint depending architecture
6. log status

### Safety

Do not submit:

- drafts
- private posts
- non-indexable URLs
- admin/login URLs
- URLs blocked by robots/noindex where known

## URL submission UI

User actions:

- submit one URL
- submit selected URLs
- auto-submit on publish toggle
- view recent submission history

## Metrics

Track:

- submissions attempted
- accepted/failed
- last submitted time per URL
- error reason

Do not promise indexing. Submission is a discovery signal, not a guaranteed index result.

## Acceptance criteria

- integration stores credentials securely
- site mapping is tenant-scoped
- scheduled sync is idempotent
- Bing data can be viewed independently from GSC
- IndexNow only sends eligible canonical URLs
- failed submissions are retried only for retryable errors
