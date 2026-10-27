"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { apiGet } from "@/lib/api";

/**
 * Target lineup (roadmap 24). Slugs must match the API's plan slugs — the
 * CTA carries ?plan= through /register into the billing page's preselect.
 */
const FALLBACK_PLANS = [
  { slug: "free", name: "Free Connected", price: "$0", yearly: "$0", sites: "1 site", isFree: true, feats: ["Basic website analytics", "10 basic AI insights/mo", "1,000 monthly credits", "No credit card required"] },
  { slug: "starter", name: "Starter", price: "$29", yearly: "$290", sites: "1 site", feats: ["20k crawl pages/mo", "30 browser minutes/mo", "150 tracked keywords", "5,000 monthly credits"] },
  { slug: "growth", name: "Growth", price: "$69", yearly: "$690", sites: "3 sites", highlight: true, feats: ["WooCommerce intelligence", "80k crawl pages/mo", "120 browser minutes/mo", "15,000 monthly credits"] },
  { slug: "agency", name: "Agency", price: "$149", yearly: "$1,490", sites: "10 sites", feats: ["White-label reports and dashboard", "250k crawl pages/mo", "1,500 tracked keywords", "40,000 monthly credits"] },
  { slug: "agency_pro", name: "Agency Pro", price: "$299", yearly: "$2,990", sites: "30 sites", feats: ["Custom branded domain", "600k crawl pages/mo", "5,000 tracked keywords", "100,000 monthly credits"] },
];

type ApiPlan = {
  slug: string;
  name: string;
  price_monthly: number;
  price_yearly?: number;
  site_limit: number;
  is_featured?: boolean;
  features?: string[];
  limits?: Record<string, number>;
  is_purchasable?: boolean;
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
    (limits.http_crawl_pages_monthly || limits.crawl_pages_monthly) ? `${Number(limits.http_crawl_pages_monthly || limits.crawl_pages_monthly).toLocaleString()} crawl pages/mo` : null,
    limits.tracked_keywords ? `${Number(limits.tracked_keywords).toLocaleString()} tracked keywords` : null,
    limits.monthly_ai_credits ? `${Number(limits.monthly_ai_credits).toLocaleString()} monthly credits` : null,
  ].filter((value): value is string => Boolean(value));

  return {
    slug: plan.slug,
    name: plan.name,
    price: `$${Math.round(plan.price_monthly / 100)}`,
    yearly: `$${Math.round((plan.price_yearly ?? plan.price_monthly * 10) / 100)}`,
    sites: `${plan.site_limit} sites`,
    highlight: Boolean(plan.is_featured),
    isFree: plan.slug === "free",
    feats: [...limitFeatures, ...features].filter((value, index, values) => values.indexOf(value) === index).slice(0, 6),
  };
}

export function PricingCards() {
  const [plans, setPlans] = useState(FALLBACK_PLANS);
  const [annual, setAnnual] = useState(false);

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
      <div className="mb-6 flex items-center justify-center gap-2 text-sm">
        <button type="button" onClick={() => setAnnual(false)} className={`rounded-full px-4 py-2 ${!annual ? "bg-slate-900 text-white" : "border border-slate-300 text-slate-600"}`}>Monthly</button>
        <button type="button" onClick={() => setAnnual(true)} className={`rounded-full px-4 py-2 ${annual ? "bg-slate-900 text-white" : "border border-slate-300 text-slate-600"}`}>Annual — save 2 months</button>
      </div>
      <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
        {plans.map((p) => (
          <div key={p.slug} className={`flex flex-col rounded-2xl border p-6 ${p.highlight ? "border-brand ring-2 ring-brand/30" : "border-slate-200"}`}>
            {p.highlight && <div className="mb-2 inline-block self-start rounded-full bg-brand/10 px-2 py-0.5 text-xs font-medium text-brand">Most popular</div>}
            <h3 className="text-lg font-semibold text-slate-900">{p.name}</h3>
            <div className="mt-2 text-3xl font-bold text-slate-900">{annual ? ((p as any).yearly ?? p.price) : p.price}<span className="text-base font-normal text-slate-500">{annual ? "/year" : "/mo"}</span></div>
            <div className="mt-1 text-sm text-slate-500">{p.sites}</div>
            <ul className="mt-4 flex-1 space-y-2 text-sm text-slate-600">
              {p.feats.map((f) => <li key={f}>• {f}</li>)}
            </ul>
            <Link href={`/register?plan=${p.slug}&interval=${annual ? "yearly" : "monthly"}`} className={`mt-6 block rounded-lg py-2 text-center text-sm font-medium ${p.highlight ? "bg-brand text-white hover:bg-brand2" : "border border-slate-300 text-slate-700 hover:border-slate-400"}`}>
              {p.isFree ? "Create free account" : "Start 7-day free trial"}
            </Link>
          </div>
        ))}
      </div>
      <p className="mt-8 text-center text-sm text-slate-600">Every paid plan starts with a 7-day free trial — no credit card required. 30-day money-back guarantee, no questions asked.</p>
      <p className="mt-2 text-center text-sm text-slate-500">Annual prices are billed once per year: Starter $290, Growth $690, Agency $1,490, Agency Pro $2,990.</p>
    </div>
  );
}
