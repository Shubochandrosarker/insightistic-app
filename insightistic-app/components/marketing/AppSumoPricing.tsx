"use client";

import { useEffect, useState } from "react";
import { apiGet } from "@/lib/api";

type Tier = {
  slug: string;
  name: string;
  price: number;
  sites: number;
  team_member_limit: number | null;
  team_member_add_credits: number;
  standard_ai_requests: number;
  monthly_ai_credits: number;
  features: string[];
  limits?: {
    tracked_keywords?: number;
    rank_cadence?: string;
    crawl_pages_monthly?: number;
    http_crawl_pages_monthly?: number;
    browser_rendering_minutes_monthly?: number;
    client_ready_reports_monthly?: number;
    scheduled_report_limit?: number;
  };
};

const FALLBACK: Tier[] = [
  { slug: "appsumo_tier_1", name: "Tier 1", price: 49, sites: 1, team_member_limit: 3, team_member_add_credits: 10, standard_ai_requests: 100, monthly_ai_credits: 5000, features: ["ga4_analytics", "search_console_analytics", "cloudflare_analytics", "pagespeed_analytics", "rank_tracking", "local_seo_analytics", "ai_copilot", "scheduled_reports", "custom_knowledge_base"] },
  { slug: "appsumo_tier_2", name: "Tier 2", price: 79, sites: 3, team_member_limit: 0, team_member_add_credits: 10, standard_ai_requests: 200, monthly_ai_credits: 10000, features: ["ga4_analytics", "search_console_analytics", "cloudflare_analytics", "pagespeed_analytics", "rank_tracking", "local_seo_analytics", "ai_copilot", "scheduled_reports", "custom_knowledge_base", "woocommerce_analytics", "custom_workflow_builder"] },
  { slug: "appsumo_tier_3", name: "Tier 3", price: 149, sites: 10, team_member_limit: 0, team_member_add_credits: 10, standard_ai_requests: 600, monthly_ai_credits: 30000, features: ["ga4_analytics", "search_console_analytics", "cloudflare_analytics", "pagespeed_analytics", "rank_tracking", "local_seo_analytics", "ai_copilot", "scheduled_reports", "custom_knowledge_base", "woocommerce_analytics", "custom_workflow_builder", "competitor_insights", "market_insights", "white_label_reports", "priority_support"] },
  { slug: "appsumo_tier_4", name: "Tier 4", price: 269, sites: 25, team_member_limit: 0, team_member_add_credits: 10, standard_ai_requests: 1500, monthly_ai_credits: 75000, features: ["ga4_analytics", "search_console_analytics", "cloudflare_analytics", "pagespeed_analytics", "rank_tracking", "local_seo_analytics", "ai_copilot", "scheduled_reports", "custom_knowledge_base", "woocommerce_analytics", "custom_workflow_builder", "competitor_insights", "market_insights", "white_label_reports", "white_label_dashboard", "priority_support"] },
  { slug: "appsumo_tier_5", name: "Tier 5", price: 499, sites: 50, team_member_limit: 0, team_member_add_credits: 10, standard_ai_requests: 5000, monthly_ai_credits: 250000, features: ["ga4_analytics", "search_console_analytics", "cloudflare_analytics", "pagespeed_analytics", "rank_tracking", "local_seo_analytics", "ai_copilot", "scheduled_reports", "custom_knowledge_base", "woocommerce_analytics", "custom_workflow_builder", "competitor_insights", "market_insights", "white_label_reports", "white_label_dashboard", "custom_domain", "priority_support"] },
];

const ROWS: Array<{ label: string; value: (tier: Tier) => string }> = [
  { label: "Sites", value: (t) => t.sites.toLocaleString() },
  { label: "Page crawling / month", value: (t) => Number(t.limits?.http_crawl_pages_monthly ?? t.limits?.crawl_pages_monthly ?? ({ appsumo_tier_1: 10000, appsumo_tier_2: 40000, appsumo_tier_3: 100000, appsumo_tier_4: 250000, appsumo_tier_5: 600000 } as Record<string, number>)[t.slug] ?? 0).toLocaleString() + " pages" },
  { label: "Browser rendering / month", value: (t) => Number(t.limits?.browser_rendering_minutes_monthly ?? ({ appsumo_tier_1: 15, appsumo_tier_2: 60, appsumo_tier_3: 180, appsumo_tier_4: 300, appsumo_tier_5: 600 } as Record<string, number>)[t.slug] ?? 0).toLocaleString() + " min" },
  { label: "Tracked keywords", value: (t) => `${Number(t.limits?.tracked_keywords ?? (t.slug === "appsumo_tier_1" ? 10 : t.slug === "appsumo_tier_2" ? 20 : t.slug === "appsumo_tier_3" ? 40 : t.slug === "appsumo_tier_4" ? 100 : 250)).toLocaleString()} / week${t.slug === "appsumo_tier_1" ? "" : " · top 20"}` },
  { label: "Included AI actions / month", value: (t) => t.standard_ai_requests.toLocaleString() },
  { label: "Monthly Insight Credits", value: (t) => t.monthly_ai_credits.toLocaleString() },
  { label: "Reports generated / month", value: (t) => Number(t.limits?.client_ready_reports_monthly ?? ({ appsumo_tier_1: 5, appsumo_tier_2: 25, appsumo_tier_3: 100, appsumo_tier_4: 250, appsumo_tier_5: 500 } as Record<string, number>)[t.slug] ?? 0).toLocaleString() },
  { label: "Active report schedules", value: (t) => Number(t.limits?.scheduled_report_limit ?? ({ appsumo_tier_1: 1, appsumo_tier_2: 3, appsumo_tier_3: 10, appsumo_tier_4: 25, appsumo_tier_5: 50 } as Record<string, number>)[t.slug] ?? 0).toLocaleString() },
  { label: "Team members", value: (t) => t.team_member_limit === 0 ? "Unlimited" : `${t.team_member_limit} added` },
  { label: "Added member cost", value: (t) => `${t.team_member_add_credits} credits` },
];

const FEATURE_ROWS: Array<{ label: string; feature: string; from?: number }> = [
  { label: "GA4 + Search Console", feature: "ga4_analytics" },
  { label: "Cloudflare analytics", feature: "cloudflare_analytics" },
  { label: "PageSpeed analytics", feature: "pagespeed_analytics" },
  { label: "Local SEO + rank tracking", feature: "local_seo_analytics" },
  { label: "WooCommerce analysis", feature: "woocommerce_analytics" },
  { label: "AI copilot", feature: "ai_copilot" },
  { label: "Scheduled PDF reports", feature: "scheduled_reports" },
  { label: "Custom KnowledgeBase", feature: "custom_knowledge_base" },
  { label: "Custom Workflow Builder", feature: "custom_workflow_builder" },
  { label: "AI competitor insights", feature: "competitor_insights" },
  { label: "White-label reports", feature: "white_label_reports" },
  { label: "Priority support", feature: "priority_support" },
];

export function AppSumoPricing({ affiliateUrl }: { affiliateUrl: string | null }) {
  const [tiers, setTiers] = useState(FALLBACK);

  useEffect(() => {
    let active = true;
    apiGet("/public/plans")
      .then((data) => {
        const live = Array.isArray(data?.appsumo?.plans) ? data.appsumo.plans : [];
        if (!active || live.length === 0) return;
        setTiers(live.map((tier: any) => ({
          ...tier,
          team_member_limit: tier.team_member_limit == null ? null : Number(tier.team_member_limit),
          team_member_add_credits: Number(tier.team_member_add_credits ?? 10),
          standard_ai_requests: Number(tier.standard_ai_requests ?? tier.limits?.standard_ai_requests_monthly ?? 0),
          monthly_ai_credits: Number(tier.monthly_ai_credits ?? tier.limits?.monthly_ai_credits ?? 0),
          sites: Number(tier.sites ?? 0),
        })));
      })
      .catch(() => undefined);
    return () => { active = false; };
  }, []);

  return (
    <section className="mt-16 rounded-3xl border border-slate-200 bg-slate-50 p-5 sm:p-8">
      <div className="mx-auto max-w-3xl text-center">
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand">AppSumo lifetime deal</p>
        <h2 className="mt-2 text-2xl font-bold text-slate-900">Choose the lifetime tier that fits your growth</h2>
        <p className="mt-3 text-sm leading-6 text-slate-600">
          One-time access to the Insightistic workspace, with real rank and local SEO data. Variable work uses the included Insight Credits, and purchased credit packs are available when you need more tracking.
        </p>
      </div>

      <div className="mt-8 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table className="min-w-[760px] w-full border-collapse text-left text-sm">
          <thead>
            <tr className="border-b border-slate-200">
              <th className="w-56 px-4 py-4 font-semibold text-slate-500">Feature</th>
              {tiers.map((tier, index) => (
                <th key={tier.slug} className={`px-4 py-4 text-center ${index === 3 ? "bg-amber-50" : ""}`}>
                  {index === 3 && <span className="mb-1 block text-[10px] font-bold uppercase text-amber-700">Most popular</span>}
                  <span className="block font-semibold text-slate-900">{tier.name}</span>
                  <span className="mt-1 block text-xl font-bold text-slate-900">${tier.price}</span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {ROWS.map((row) => (
              <tr key={row.label} className="border-b border-slate-100">
                <th className="px-4 py-3 font-medium text-slate-600">{row.label}</th>
                {tiers.map((tier, index) => <td key={tier.slug} className={`px-4 py-3 text-center font-semibold text-slate-900 ${index === 3 ? "bg-amber-50/60" : ""}`}>{row.value(tier)}</td>)}
              </tr>
            ))}
            {FEATURE_ROWS.map((row) => (
              <tr key={row.label} className="border-b border-slate-100">
                <th className="px-4 py-3 font-medium text-slate-600">{row.label}</th>
                {tiers.map((tier, index) => <td key={tier.slug} className={`px-4 py-3 text-center ${index === 3 ? "bg-amber-50/60" : ""}`}><span className={tier.features.includes(row.feature) ? "text-emerald-600" : "text-slate-300"}>{tier.features.includes(row.feature) ? "✓" : "—"}</span></td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-6 flex flex-col items-center justify-between gap-3 text-center sm:flex-row sm:text-left">
        <p className="text-xs leading-5 text-slate-500">AppSumo listing and redemption are managed by AppSumo. Team additions use 10 credits each; Tiers 2–5 have unlimited added members.</p>
        {affiliateUrl ? <a href={affiliateUrl} target="_blank" rel="noopener noreferrer" className="shrink-0 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand2">Get the AppSumo deal</a> : <span className="shrink-0 rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-medium text-slate-500">Listing pending approval</span>}
      </div>
    </section>
  );
}
