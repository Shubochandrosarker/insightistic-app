"use client";

import { useEffect, useState } from "react";
import { apiGet } from "@/lib/api";

const FALLBACK = [
  [1, 49], [2, 79], [3, 149], [4, 269], [5, 499],
].map(([tier, price]) => ({
  slug: `appsumo_tier_${tier}`,
  name: `AppSumo Tier ${tier}`,
  price,
  sites: tier === 1 ? 1 : tier === 2 ? 3 : tier === 3 ? 10 : tier === 4 ? 25 : 50,
  credits: tier === 1 ? 5000 : tier === 2 ? 10000 : tier === 3 ? 30000 : tier === 4 ? 75000 : 250000,
}));

export function AppSumoPricing() {
  const [catalog, setCatalog] = useState<any>(null);
  useEffect(() => { apiGet("/public/plans").then(setCatalog).catch(() => undefined); }, []);

  const plans = Array.isArray(catalog?.appsumo?.plans) && catalog.appsumo.plans.length
    ? catalog.appsumo.plans
    : FALLBACK;

  return (
    <section className="mt-12 rounded-2xl border border-slate-200 bg-slate-50 p-6">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-lg font-semibold text-slate-900">AppSumo lifetime tiers</h2>
        <span className="text-xs text-slate-500">Marketplace redemption · no recurring charge</span>
      </div>
      <p className="mt-2 text-sm text-slate-600">
        AppSumo Tier 1–5 are lifetime entitlements redeemed through AppSumo. They are not Stripe
        subscriptions and are not purchased from this page.
      </p>
      <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        {plans.map((tier: any) => (
          <div key={tier.slug} className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex items-baseline justify-between gap-2">
              <span className="text-sm font-semibold text-slate-900">{tier.name}</span>
              <span className="text-lg font-bold text-slate-900">${tier.price}</span>
            </div>
            <p className="mt-2 text-xs leading-relaxed text-slate-500">
              {tier.sites} sites · {Number(tier.monthly_ai_credits ?? tier.credits ?? 0).toLocaleString()} monthly credits
            </p>
          </div>
        ))}
      </div>
    </section>
  );
}
