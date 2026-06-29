# Insightistic Marketing — WordPress Theme

A premium, mobile-first **dark SaaS** marketing theme for **Insightistic** —
AI Business Analytics for WordPress & WooCommerce. It powers the public
marketing website at `insightistic.com` only. Accounts, billing and the
dashboard live in the separate SaaS app (`app.insightistic.com`) and API
(`api.insightistic.com`); **no dashboard logic lives in this theme.**

- No page builder, no jQuery dependency, no remote fonts.
- Native WordPress blocks/templates + reusable template parts.
- Mobile-first CSS, fluid type, 44px tap targets, accessible nav & FAQ.
- SEO-ready (title-tag, schema, breadcrumbs) and SEO-plugin friendly.
- Formistic-ready form areas with graceful fallbacks.

## Requirements

- WordPress 6.4+ and PHP 8.0+.
- (Optional) **Formistic** plugin for contact / newsletter / lead forms.
- (Optional) An SEO plugin (Rank Math / Yoast) — the theme won't duplicate meta.

## Install

1. Zip the `insightistic-marketing/` folder (or upload it to
   `wp-content/themes/insightistic-marketing`).
2. **Appearance → Themes → Add New → Upload**, then **Activate**.
3. **Appearance → Customize → Insightistic** to set:
   - App / API / Marketing URLs
   - Hero eyebrow, title, subtitle
   - Formistic form ids (contact, newsletter, agency, ltd, …)
   - Footer tagline + social links
4. **Appearance → Menus**: create menus and assign to **Primary**, **Footer**
   and **Legal** locations.
5. **Settings → Reading**: set a static front page (any page) — the homepage
   uses `front-page.php` automatically, so the 14-section landing renders
   regardless of which page you choose.

## Required pages & templates

Create these pages and assign the matching **Page Template** (Page → Page
Attributes → Template):

| Page | Template |
|---|---|
| Pricing (`/pricing`) | Pricing |
| Features (`/features`) | Features |
| Contact (`/contact`) | Contact |
| About (`/about`) | About |
| Integrations (`/integrations`) | Integrations |
| WooCommerce Analytics (`/woocommerce-analytics`) | WooCommerce Analytics |
| AI Insights (`/ai-insights`) | AI Insights |
| Reports (`/reports`) | Reports |
| Agency (`/agency`) | Agency |
| Lifetime Deal (`/lifetime-deal`) | Lifetime Deal |
| Privacy Policy (`/privacy-policy`) | Privacy Policy |
| Terms (`/terms`) | Terms |

The blog index is whatever you set under **Settings → Reading → Posts page**.

## App routing

Every CTA links to the app via the `insightistic_get_app_url()` helper:

```php
echo esc_url( insightistic_get_app_url( '/login' ) );
echo esc_url( insightistic_register_url( 'pro' ) ); // /register?plan=pro
echo esc_url( insightistic_dashboard_url() );
```

Defaults: `https://app.insightistic.com` — change in **Customize → Insightistic
→ App & API URLs**. Plan CTAs already point at
`/register?plan=starter|pro|agency`.

## Formistic forms

Form areas degrade gracefully: if Formistic isn't active, a clear message shows
instead. Map each area to a Formistic form id in **Customize → Forms**. Areas:
`contact`, `newsletter`, `agency`, `ltd`, `early_access`, `support`.

```php
insightistic_formistic_form( 'contact', 'Send us a message' );
```

Consent line to use under forms:

> By submitting this form, you agree to our [Privacy Policy](/privacy-policy).

## Demo content (optional)

- **Testimonials**: add entries under the **Testimonials** menu (or rely on the
  built-in defaults). Use the page-attributes *Order* to sort; add a role in
  the body via custom field `_ins_role` if desired.
- **Pricing**: edit the `insightistic_pricing_plans` array (filterable) or the
  `inc/helpers.php` defaults.
- **FAQ / Features**: edit `insightistic_faqs()` / `insightistic_features()` in
  `inc/helpers.php`, or hook the `insightistic_faqs` / `insightistic_features`
  filters from a small mu-plugin.

## Performance & SEO notes

- CSS is split into `theme.css` (base, mobile-first), `responsive.css` and
  `blocks.css`. JS is two small deferred files; the pricing toggle only loads
  where a pricing table exists.
- The FAQ outputs `FAQPage` JSON-LD. `title-tag`, post thumbnails and excerpts
  are supported; the theme defers meta titles/descriptions to your SEO plugin.

## Deployment checklist

- [ ] Theme uploaded to `wp-content/themes/insightistic-marketing` and activated.
- [ ] Customizer: App/API/Marketing URLs set.
- [ ] Menus assigned (Primary, Footer, Legal).
- [ ] All required pages created with the correct templates.
- [ ] Static front page set under Settings → Reading.
- [ ] Formistic installed + form ids mapped (or fallback accepted).
- [ ] Permalinks set to "Post name" (Settings → Permalinks).
- [ ] Test on 360 / 390 / 768 / 1024 / 1440 widths — no horizontal scroll.
- [ ] Verify CTAs open `app.insightistic.com/login` and `/register?plan=...`.

See `Appearance → Insightistic` for a live summary of URLs and integration status.
