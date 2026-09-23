"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { apiGet } from "@/lib/api";

/**
 * Target lineup (roadmap 24). Slugs must match the API's plan slugs — the
 * CTA carries ?plan= through /register into the billing page's preselect.
 */
const FALLBACK_PLANS = [
  { slug: "starter", name: "Starter", price: "$29", sites: "3 sites", feats: ["Search + AI visibility basics", "10k crawl pages/mo", "150 tracked keywords", "15 AI prompts/mo"] },
  { slug: "growth", name: "Growth", price: "$69", sites: "10 sites", highlight: true, feats: ["Everything in Starter", "WooCommerce intelligence", "50k crawl pages/mo", "750 keywords · 75 AI prompts"] },
  { slug: "agency", name: "Agency", price: "$149", sites: "30 sites", feats: ["White-label reports", "Portfolio dashboard", "200k crawl pages/mo", "3k keywords · 250 AI prompts"] },
  { slug: "agency_pro", name: "Agency Pro", price: "$299", sites: "100 sites", feats: ["Custom domain", "Team of 30", "1M crawl pages/mo", "10k keywords · 1000 AI prompts"] },
];

type ApiPlan = {
  slug: string;
  name: string;
  price_monthly: number;
  site_limit: number;
  is_featured?: boolean;
  features?: string[];
  limits?: Record<string, number>;
};

const FEATURE_LABELS: Record<string, string> = {
  basic_analytics: "Core website intelligence",
  woocommerce_analytics: "WooCommerce intelligence",
  seo_analysis: "SEO analysis",
  email_audit_automation: "Email audit automation",
  ai_insights: "AI business insights",
  advanced_reports: "Branded reports",
  scheduled_reports: "Scheduled reports",
  multi_site: "Multiple connected sites",
  agency_white_label: "White-label reports",
  team_access: "Team roles and access",
};

function apiPlanToCard(plan: ApiPlan) {
  const limits = plan.limits ?? {};
  const features = (plan.features ?? []).map((feature) => FEATURE_LABELS[feature] ?? feature.replaceAll("_", " "));
  const limitFeatures = [
    plan.site_limit ? `${plan.site_limit} sites` : null,
    limits.crawl_pages_monthly ? `${Number(limits.crawl_pages_monthly).toLocaleString()} crawl pages/mo` : null,
    limits.tracked_keywords ? `${Number(limits.tracked_keywords).toLocaleString()} tracked keywords` : null,
    limits.ai_prompt_runs_monthly ? `${Number(limits.ai_prompt_runs_monthly).toLocaleString()} AI prompts/mo` : null,
  ].filter((value): value is string => Boolean(value));

  return {
    slug: plan.slug,
    name: plan.name,
    price: `$${Math.round(plan.price_monthly / 100)}`,
    sites: `${plan.site_limit} sites`,
    highlight: Boolean(plan.is_featured),
    feats: [...limitFeatures, ...features].filter((value, index, values) => values.indexOf(value) === index).slice(0, 6),
  };
}

export function PricingCards() {
  const [plans, setPlans] = useState(FALLBACK_PLANS);

  useEffect(() => {
    let active = true;
    apiGet("/public/plans")
      .then((data) => {
        if (!active || !Array.isArray(data?.plans) || data.plans.length === 0) return;
        setPlans(data.plans.map(apiPlanToCard));
      })
      .catch(() => undefined);
    return () => { active = false; };
  }, []);

  return (
    <div>
      <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
        {plans.map((p) => (
          <div key={p.slug} className={`flex flex-col rounded-2xl border p-6 ${p.highlight ? "border-brand ring-2 ring-brand/30" : "border-slate-200"}`}>
            {p.highlight && <div className="mb-2 inline-block self-start rounded-full bg-brand/10 px-2 py-0.5 text-xs font-medium text-brand">Most popular</div>}
            <h3 className="text-lg font-semibold text-slate-900">{p.name}</h3>
            <div className="mt-2 text-3xl font-bold text-slate-900">{p.price}<span className="text-base font-normal text-slate-500">/mo</span></div>
            <div className="mt-1 text-sm text-slate-500">{p.sites}</div>
            <ul className="mt-4 flex-1 space-y-2 text-sm text-slate-600">
              {p.feats.map((f) => <li key={f}>• {f}</li>)}
            </ul>
            <Link href={`/register?plan=${p.slug}`} className={`mt-6 block rounded-lg py-2 text-center text-sm font-medium ${p.highlight ? "bg-brand text-white hover:bg-brand2" : "border border-slate-300 text-slate-700 hover:border-slate-400"}`}>
              Start free trial
            </Link>
          </div>
        ))}
      </div>
      <p className="mt-8 text-center text-sm text-slate-500">Annual billing = 2 months free.</p>
    </div>
  );
}
