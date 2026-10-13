/**
 * Tier-aware visibility for the billing page (founder order #4, 2026-10-13):
 * a buyer should only ever be shown purchases ABOVE what they already have.
 *
 *   recurring tiers: starter(1) < growth(2) < agency(3) < agency_pro(4)
 *   founder LTD tiers are one-time grants, ordered 1 < 2 < 3 and roughly
 *   equivalent to starter / growth / agency (founder_tier_3: 30 sites /
 *   100k crawl sits just under Agency's 30 sites / 200k crawl — equal
 *   sites, so the equivalence hides Agency for a tier-3 owner).
 *
 * Rules:
 *  - a paid recurring user sees only plans strictly above their tier;
 *    the top plan (agency_pro) sees an empty buy list;
 *  - a lifetime owner sees no recurring plans at-or-below their grant's
 *    equivalent tier (higher tiers show as upgrade), and no founder tier
 *    at-or-below their grant;
 *  - symmetric guard: a recurring paid user sees no founder tier at-or-
 *    below their own tier — buying a lifetime tier beneath your current
 *    plan is a refund ticket, not an upsell;
 *  - free/trial users see everything.
 *
 * Pure functions — no React, no network — so the rules are unit-testable.
 */

/** Recurring plan order (slugs from the public plans catalog). */
export const PLAN_ORDER: Record<string, number> = {
  starter: 1,
  growth: 2,
  agency: 3,
  agency_pro: 4,
};

export const FOUNDER_TIER_ORDER: Record<string, number> = {
  founder_tier_1: 1,
  founder_tier_2: 2,
  founder_tier_3: 3,
};

/** Founder tier N ≈ recurring tier of this rank (see module docblock). */
export const FOUNDER_TIER_EQUIV_RANK: Record<string, number> = {
  founder_tier_1: 1, // starter
  founder_tier_2: 2, // growth
  founder_tier_3: 3, // agency
};

export const TOP_PLAN = "agency_pro";

/** Founder LTD grants ride on the subscription payload. */
export type LifetimeGrant = { active?: boolean; plan_slug?: string } | null | undefined;

/** Entitlement state the billing page derives from /billing/subscription. */
export type BillingEntitlement = {
  /** Recurring plan slug (data.plan.slug). */
  planSlug?: string | null;
  /** Organization status — "trialing" is not a paid tier. */
  organizationStatus?: string | null;
  /** The one-time founder grant, when present and active. */
  lifetimeGrant?: LifetimeGrant;
};

/** founder_tier_2 -> "Tier 2"; unknown slugs fall back to spaced words. */
export function grantTierLabel(planSlug: string): string {
  const m = planSlug.match(/founder_tier_(\d+)/);
  return m ? `Tier ${m[1]}` : planSlug.replace(/_/g, " ");
}

/** The lifetime grant only counts when it is marked active and named. */
export function lifetimeGrantOf(d: any): LifetimeGrant {
  const g = d?.lifetime_grant ?? d?.subscription?.lifetime_grant;
  return g?.active && g?.plan_slug ? g : null;
}

/** True when the org is on a paid recurring plan (not trial, not free). */
export function hasPaidPlan(e: BillingEntitlement): boolean {
  if (e.lifetimeGrant) return true;
  return !!e.planSlug &&
    e.planSlug in PLAN_ORDER &&
    e.organizationStatus !== "trialing";
}

/**
 * Rank of what the org already owns on the shared ladder: 0 for free/
 * trial, 1-4 recurring, and a founder grant ranks as its equivalent
 * recurring tier. The maximum of the two applies when both exist.
 */
export function ownedRank(e: BillingEntitlement): number {
  let rank = 0;
  if (e.planSlug && e.planSlug in PLAN_ORDER && e.organizationStatus !== "trialing") {
    rank = PLAN_ORDER[e.planSlug];
  }
  if (e.lifetimeGrant?.plan_slug) {
    rank = Math.max(rank, FOUNDER_TIER_EQUIV_RANK[e.lifetimeGrant.plan_slug] ?? 0);
  }
  return rank;
}

/**
 * Which recurring plans remain buyable: only tiers strictly ABOVE the
 * owned rank. A top-plan user gets an empty list — no lower plan may
 * render as a purchase. Free/trial users see everything.
 */
export function buyablePlans<T extends { slug: string }>(
  plans: T[],
  e: BillingEntitlement,
): T[] {
  const rank = ownedRank(e);
  if (rank === 0) return plans;
  return plans.filter((p) => (PLAN_ORDER[p.slug] ?? 0) > rank);
}

/** "Upgrade to Pro" stays visible on the top plan; the buy list does not. */
export function isTopPlan(e: BillingEntitlement): boolean {
  return e.planSlug === TOP_PLAN;
}

/**
 * Which founder tiers stay purchasable: none at-or-below what the org
 * already owns (grant tier or recurring-tier equivalent). Free/trial
 * users see all tiers.
 */
export function buyableFounderTiers<T extends { slug: string }>(
  tiers: T[],
  e: BillingEntitlement,
): T[] {
  const rank = ownedRank(e);
  if (rank === 0) return tiers;
  return tiers.filter((t) => (FOUNDER_TIER_EQUIV_RANK[t.slug] ?? 0) > rank);
}

/**
 * Which credit packs a given buyer may purchase: all of them. Packs are
 * top-ups, not plan tiers — a bigger pack does not supersede a smaller
 * one. Named so the rule is documented and testable.
 */
export function buyableCreditPacks<T>(packs: T[]): T[] {
  return packs;
}
