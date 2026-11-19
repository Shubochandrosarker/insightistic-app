# Live GSC, logo, footer, and responsive QA

**Date:** 2026-11-17  
**Time zone:** Asia/Dhaka  
**Status:** Production changes deployed; external Google recrawl pending

## Scope

Fixed the public Insightistic marketing site on Hostinger after the Google Search Console Merchant listings/Product snippet warnings and the user-reported logo, footer, contact-layout, and mobile-quality issues.

## Production target

- Domain: `https://www.insightistic.com`
- Hostinger account username: `u303083954` (no credentials stored here)
- WordPress document root: `/home/u303083954/domains/insightistic.com/public_html`

## Changes deployed

- Removed commercial Product/Offer structured data from the SEO override and theme fallback. Insightistic is a SaaS product, so no invented review, rating, shipping, returns, GTIN, SKU, or merchant fields were added.
- Removed the rendered `Company & Trust / Clear answers start with clear sources` footer section from the active edge agent. Existing company/legal/social footer links and machine-readable bot resources remain available.
- Standardized header/footer/schema image references to the approved full wordmark copied from `D:\All Projects\WPISTIC-GALAXY-ECOSYSTEM\INSIGHTISTIC\Graphic Assets\Insightistic Logo\Insightistic Gradient Eye Logo.png`.
- Deployed the new asset as `wp-content/themes/insightistic-marketing/assets/images/insightistic-brand-logo.png`.
- Simplified the contact page to one canonical Formistic contact form, removing the duplicate visible heading while retaining the separate footer newsletter form.
- Added mobile footer link touch targets of at least 44px and preserved the existing responsive 2-column desktop / 1-column mobile contact layout.

## Files uploaded

- `wp-content/themes/insightistic-marketing/page-templates/template-contact.php`
- `wp-content/themes/insightistic-marketing/assets/css/theme.css`
- `wp-content/themes/insightistic-marketing/assets/images/insightistic-brand-logo.png`
- `wp-content/themes/insightistic-marketing/inc/seo.php`
- `wp-content/mu-plugins/insightistic-seo-overrides.php`
- `wp-content/plugins/insightistic-edge-agent-1.0.0-q6G6vtfCmoMs9rw3/insightistic-edge-agent.php`

## Verification evidence

- Direct live route sweep: all requested public routes returned HTTP 200.
- Route body check: no `critical error`, `fatal error`, or `parse error` text.
- Live JSON-LD check on the homepage, pricing, features, and contact routes: `Product=0`, `SoftwareApplication=0`, and `offers=0`.
- Live contact markup: one `.ins-contact__form` wrapper, one actual contact form, one footer newsletter form, one `Send Us a Message` heading, and zero public trust sections.
- Live legacy-logo check: no `logo-dark.svg` or `insightistic-icon.png` references on the checked pages.
- PHP syntax checks passed for the changed PHP files.
- Hostinger server/CDN cache clear returned `Request accepted`.
- The LiteSpeed WP-CLI purge was attempted but unavailable because `litespeed-purge` was not registered; this is recorded rather than claimed as successful.

## Responsive QA limitation

A valid normal Edge session had already checked the live contact page at 320, 375, 390, 430, 768, 820, 1024, and 1440 CSS pixels with no horizontal overflow, broken images, or critical-error text. A later automated Playwright run received the Hostinger browser-check page with HTTP 403 at every viewport, so those automated results were discarded as invalid layout evidence. Run the final visual/device pass in a normal browser after the WAF challenge is cleared.

## Follow-up

1. Use Search Console to start validation/reinspection after Google recrawls the affected URLs.
2. Capture a fresh PageSpeed mobile and desktop report when the test endpoint is not rate-limited.
3. Keep this record and `Memory/current-status.md` updated after the next production verification.
