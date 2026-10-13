import { describe, it, expect } from "vitest";
import {
  buyablePlans,
  buyableFounderTiers,
  buyableCreditPacks,
  grantTierLabel,
  hasPaidPlan,
  isTopPlan,
  lifetimeGrantOf,
  ownedRank,
  type BillingEntitlement,
} from "@/lib/billing/tiers";

/**
 * Founder order #4 (2026-10-13): a paying user sees only UPPERA plans as
 * buyable; the top plan sees no lower-plan purchase list; a lifetime owner
 * is never shown buy buttons at-or-below their grant tier.
 */

const CATALOG = [
  { slug: "starter", name: "Starter" },
  { slug: "growth", name: "Growth" },
  { slug: "agency", name: "Agency" },
  { slug: "agency_pro", name: "Agency Pro" },
];

const TIERS = [{ slug: "founder_tier_1" }, { slug: "founder_tier_2" }, { slug: "founder_tier_3" }];

const free: BillingEntitlement = { organizationStatus: "trialing", planSlug: null };
const starter: BillingEntitlement = { planSlug: "starter", organizationStatus: "active" };
const growth: BillingEntitlement = { planSlug: "growth", organizationStatus: "active" };
const agency: BillingEntitlement = { planSlug: "agency", organizationStatus: "active" };
const pro: BillingEntitlement = { planSlug: "agency_pro", organizationStatus: "active" };

const grant = (tier: string): BillingEntitlement => ({
  organizationStatus: "active",
  planSlug: null,
  lifetimeGrant: { active: true, plan_slug: tier },
});

describe("lifetimeGrantOf", () => {
  it("reads the grant from the payload or the subscription", () => {
    expect(lifetimeGrantOf({ lifetime_grant: { active: true, plan_slug: "founder_tier_2" } }))
      .toEqual({ active: true, plan_slug: "founder_tier_2" });
    expect(lifetimeGrantOf({ subscription: { lifetime_grant: { active: true, plan_slug: "founder_tier_1" } } }))
      .toEqual({ active: true, plan_slug: "founder_tier_1" });
  });

  it("ignores inactive or unnamed grants", () => {
    expect(lifetimeGrantOf({ lifetime_grant: { active: false, plan_slug: "founder_tier_2" } })).toBeNull();
    expect(lifetimeGrantOf({ lifetime_grant: { active: true } })).toBeNull();
    expect(lifetimeGrantOf({})).toBeNull();
  });
});

describe("grantTierLabel", () => {
  it("formats founder tier slugs", () => {
    expect(grantTierLabel("founder_tier_2")).toBe("Tier 2");
    expect(grantTierLabel("other_plan")).toBe("other plan");
  });
});

describe("hasPaidPlan / ownedRank", () => {
  it("trialing is not paid; rank 0", () => {
    expect(hasPaidPlan(free)).toBe(false);
    expect(ownedRank(free)).toBe(0);
  });

  it("recurring plans rank on the shared ladder", () => {
    expect(ownedRank(starter)).toBe(1);
    expect(ownedRank(growth)).toBe(2);
    expect(ownedRank(agency)).toBe(3);
    expect(ownedRank(pro)).toBe(4);
    expect(hasPaidPlan(pro)).toBe(true);
  });

  it("a trialing org with a plan slug still ranks 0", () => {
    expect(ownedRank({ planSlug: "growth", organizationStatus: "trialing" })).toBe(0);
  });

  it("a founder grant ranks as its equivalent recurring tier", () => {
    expect(ownedRank(grant("founder_tier_1"))).toBe(1);
    expect(ownedRank(grant("founder_tier_2"))).toBe(2);
    expect(ownedRank(grant("founder_tier_3"))).toBe(3);
    expect(hasPaidPlan(grant("founder_tier_1"))).toBe(true);
  });

  it("both subscription and grant: the higher applies", () => {
    expect(ownedRank({
      planSlug: "starter",
      organizationStatus: "active",
      lifetimeGrant: { active: true, plan_slug: "founder_tier_3" },
    })).toBe(3);
  });
});

describe("buyablePlans", () => {
  it("shows everything to free/trial users", () => {
    expect(buyablePlans(CATALOG, free)).toHaveLength(4);
  });

  it("shows only strictly higher tiers to paid users", () => {
    expect(buyablePlans(CATALOG, growth).map((p) => p.slug)).toEqual(["agency", "agency_pro"]);
    expect(buyablePlans(CATALOG, starter).map((p) => p.slug)).toEqual(["growth", "agency", "agency_pro"]);
    expect(buyablePlans(CATALOG, agency).map((p) => p.slug)).toEqual(["agency_pro"]);
  });

  it("shows NO lower-plan purchase list on the top plan (founder order #4)", () => {
    const buyable = buyablePlans(CATALOG, pro);
    expect(buyable).toEqual([]);
    expect(isTopPlan(pro)).toBe(true);
  });

  it("a lifetime owner sees no recurring buy list at-or-below their grant", () => {
    expect(buyablePlans(CATALOG, grant("founder_tier_3")).map((p) => p.slug)).toEqual(["agency_pro"]);
    expect(buyablePlans(CATALOG, grant("founder_tier_2")).map((p) => p.slug)).toEqual(["agency", "agency_pro"]);
  });
});

describe("buyableFounderTiers", () => {
  it("shows all tiers without a grant", () => {
    expect(buyableFounderTiers(TIERS, free)).toHaveLength(3);
    expect(buyableFounderTiers(TIERS, { organizationStatus: null })).toHaveLength(3);
  });

  it("hides tiers at-or-below the owned grant", () => {
    expect(buyableFounderTiers(TIERS, grant("founder_tier_1")).map((t) => t.slug)).toEqual(["founder_tier_2", "founder_tier_3"]);
    expect(buyableFounderTiers(TIERS, grant("founder_tier_3"))).toEqual([]);
  });

  it("a recurring paid user sees no lifetime tier at-or-below their plan", () => {
    expect(buyableFounderTiers(TIERS, agency)).toEqual([]);
    expect(buyableFounderTiers(TIERS, growth).map((t) => t.slug)).toEqual(["founder_tier_3"]);
  });
});

describe("buyableCreditPacks", () => {
  it("packs are top-ups, never tiered — always all of them", () => {
    const packs = [{ credits: 5000 }, { credits: 20000 }, { credits: 50000 }];
    expect(buyableCreditPacks(packs)).toHaveLength(3);
    expect(buyableCreditPacks(packs)).toBe(packs);
  });
});
