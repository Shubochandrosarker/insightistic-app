/**
 * Founding Angel lifetime-deal (LTD) offer visibility.
 *
 * The public LTD sales page is hidden unless APPSUMO_AFFILIATE_URL is set.
 * It is ONE server-side runtime value, read at request time by proxy.ts, the
 * /ltd page and /pricing, so it can be switched on and off without rebuilding
 * the image. While it is empty:
 *   - every LTD offer URL answers 302 (temporary, never 301) to /pricing with
 *     `X-Robots-Tag: noindex, nofollow`;
 *   - nothing links to the offer.
 * When it is set, /ltd renders and every Buy Now button points at that URL.
 *
 * Existing Founding Angel holders are unaffected: their grant, licenses and
 * /dashboard/billing (including Stripe payment returns) are never
 * redirected. Prices are not defined here.
 */

/** Offer pages; each also matches its sub-paths (/ltd/anything). */
export const LTD_OFFER_PATHS = ["/ltd", "/lifetime", "/lifetime-deal", "/founding-angel", "/founding-angels"] as const;

/** ?plan= values that start a Founding Angel purchase (current + legacy slugs). */
export const LTD_PLAN_PATTERN = /^(angel_|founder_tier_)/;

export const LTD_HIDDEN_REDIRECT = "/pricing";
export const LTD_NOINDEX = "noindex, nofollow";

/** The AppSumo affiliate URL, or null while the offer is hidden (empty or not an http(s) URL). */
export function appsumoAffiliateUrl(env: Record<string, string | undefined> = process.env): string | null {
  const raw = (env.APPSUMO_AFFILIATE_URL ?? "").trim();
  if (!raw) return null;
  try {
    const u = new URL(raw);
    return u.protocol === "https:" || u.protocol === "http:" ? raw : null;
  } catch {
    return null;
  }
}

export function isLtdOfferPath(pathname: string): boolean {
  return LTD_OFFER_PATHS.some((p) => pathname === p || pathname.startsWith(`${p}/`));
}

export function isLtdPlan(plan: string | null | undefined): boolean {
  return LTD_PLAN_PATTERN.test(plan ?? "");
}
