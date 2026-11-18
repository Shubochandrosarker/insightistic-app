"use client";

import { useEffect, useMemo, useState } from "react";
import { useApi } from "@/lib/useApi";
import { useDashboard } from "@/lib/dashboard";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";

const RECURRING = ["starter", "growth", "agency", "agency_pro"];
const RANK: Record<string, number> = { free: 0, starter: 10, growth: 20, agency: 30, agency_pro: 40 };
const FALLBACK = [
  { slug: "starter", name: "Starter", price_monthly: 2900, price_yearly: 29000, site_limit: 1 },
  { slug: "growth", name: "Growth", price_monthly: 6900, price_yearly: 69000, site_limit: 3 },
  { slug: "agency", name: "Agency", price_monthly: 14900, price_yearly: 149000, site_limit: 10 },
  { slug: "agency_pro", name: "Agency Pro", price_monthly: 29900, price_yearly: 299000, site_limit: 30 },
];

function lifetimeGrantOf(data: any): any | null {
  const grant = data?.lifetime_grant ?? data?.subscription?.lifetime_grant;
  return grant?.active && grant?.plan_slug ? grant : null;
}

function lifetimeLabel(slug?: string): string {
  const match = String(slug || "").match(/^appsumo_tier_(\d+)$/);
  return match ? `AppSumo Tier ${match[1]}` : String(slug || "Lifetime entitlement").replaceAll("_", " ");
}

function money(cents: unknown): string {
  const value = Number(cents ?? 0);
  return `$${(value / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

function UsageBar({ label, used, limit }: { label: string; used: number; limit: number | null }) {
  const ratio = limit && limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between text-sm">
        <span className="text-muted">{label}</span>
        <span className="font-semibold text-fg">{num(used)} / {limit == null ? "∞" : num(limit)}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-black/10 dark:bg-white/10">
        <div className="h-full rounded-full bg-gradient-to-r from-brand2 to-brand" style={{ width: `${ratio}%` }} />
      </div>
    </div>
  );
}

export default function BillingPage() {
  const { data, loading, error, reload } = useApi<any>("/billing/subscription");
  const { data: catalog } = useApi<any>("/public/plans");
  const { sites } = useDashboard();
  const [selectedPlan, setSelectedPlan] = useState("growth");
  const [interval, setInterval] = useState<"monthly" | "yearly">("monthly");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  const grant = lifetimeGrantOf(data);
  const currentSlug = String(grant?.plan_slug || data?.plan?.slug || "free");
  const currentRank = RANK[currentSlug] ?? 0;
  const recurring = useMemo(() => {
    const source = Array.isArray(catalog?.plans) && catalog.plans.length ? catalog.plans : FALLBACK;
    return source.filter((plan: any) => RECURRING.includes(String(plan.slug)) && plan.is_purchasable !== false);
  }, [catalog]);
  const upgrades = recurring.filter((plan: any) => (RANK[String(plan.slug)] ?? 0) > currentRank);
  const appsumo = Array.isArray(catalog?.appsumo?.plans) ? catalog.appsumo.plans : [];

  useEffect(() => {
    const query = new URLSearchParams(window.location.search);
    const requested = query.get("plan");
    const requestedInterval = query.get("interval");
    if (RECURRING.includes(String(requested))) setSelectedPlan(String(requested));
    if (requestedInterval === "monthly" || requestedInterval === "yearly") setInterval(requestedInterval);
  }, []);

  useEffect(() => {
    if (upgrades.length && !upgrades.some((plan: any) => plan.slug === selectedPlan)) {
      setSelectedPlan(String(upgrades[0].slug));
    }
  }, [upgrades, selectedPlan]);

  async function checkout() {
    if (!selectedPlan) return;
    setBusy(true);
    setMessage(null);
    try {
      const result = await apiPost("/billing/checkout", { plan: selectedPlan, interval });
      if (!result?.url) throw new Error("Stripe did not return a checkout URL.");
      window.location.assign(result.url);
    } catch (err: any) {
      setMessage(err?.message || "Stripe checkout could not be started.");
      setBusy(false);
    }
  }

  async function portal() {
    setBusy(true);
    setMessage(null);
    try {
      const result = await apiPost("/billing/portal");
      if (!result?.url) throw new Error("Stripe did not return a portal URL.");
      window.location.assign(result.url);
    } catch (err: any) {
      setMessage(err?.message || "Could not open the Stripe billing portal.");
      setBusy(false);
    }
  }

  async function cancel() {
    if (!window.confirm("Cancel your subscription at the end of the current billing period?")) return;
    setBusy(true);
    setMessage(null);
    try {
      const result = await apiPost("/billing/cancel");
      setMessage(result?.message || "Cancellation scheduled.");
      reload();
    } catch (err: any) {
      setMessage(err?.message || "Could not cancel the subscription.");
    } finally {
      setBusy(false);
    }
  }

  async function reactivate() {
    setBusy(true);
    setMessage(null);
    try {
      const result = await apiPost("/billing/reactivate");
      setMessage(result?.message || "Subscription reactivated.");
      reload();
    } catch (err: any) {
      setMessage(err?.message || "Could not reactivate the subscription.");
    } finally {
      setBusy(false);
    }
  }

  if (error && !data) {
    return <><PageHeader title="Billing" subtitle="Your plan, usage, and billing status." /><ErrorState message={error} onRetry={reload} /></>;
  }
  if (loading || !data) return <Spinner />;

  const plan = data.plan || {};
  const usage = data.usage || {};
  const subscription = data.subscription || null;
  const cancelPending = Boolean(subscription?.cancel_at_period_end);
  const hasStripeSubscription = Boolean(subscription?.stripe_subscription_id || subscription?.billing_provider === "stripe");
  const lifetime = Boolean(grant || plan?.limits?.billing_model === "lifetime");
  const included = Number(data.credits?.monthly_included?.granted ?? plan?.limits?.monthly_ai_credits ?? 0);
  const remaining = Number(data.credits?.monthly_included?.remaining ?? 0);

  return (
    <div>
      <PageHeader title="Billing" subtitle="Your plan, usage, and billing status." />

      {message && <p className="mb-5 rounded-xl border border-warn/30 bg-warn/10 px-4 py-3 text-sm text-fg">{message}</p>}

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Current entitlement" subtitle="The server resolves this from your active plan or lifetime grant.">
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-2xl font-bold text-fg">{grant ? lifetimeLabel(grant.plan_slug) : plan.name || "Free trial"}</span>
            <Badge tone={lifetime ? "green" : "slate"} dot>{lifetime ? "Lifetime" : "Stripe subscription"}</Badge>
          </div>
          <p className="mt-3 text-sm text-muted">
            {lifetime
              ? "This AppSumo entitlement is lifetime access and is not changed by Stripe subscription checkout."
              : `${money(plan.price_monthly)} / month or ${money(plan.price_yearly)} / year, according to the selected Stripe price.`}
          </p>
          <div className="mt-5 flex flex-wrap gap-2">
            {hasStripeSubscription && <Button variant="ghost" onClick={portal} disabled={busy}>Manage in Stripe</Button>}
            {hasStripeSubscription && !cancelPending && <Button variant="danger" onClick={cancel} disabled={busy}>Cancel subscription</Button>}
            {hasStripeSubscription && cancelPending && <Button variant="soft" onClick={reactivate} disabled={busy}>Reactivate</Button>}
          </div>
        </Card>

        <Card title="Usage this period" subtitle="All displayed counters come from the same server-side usage payload.">
          <div className="space-y-4">
            <UsageBar label="AI insights" used={Number(usage.ai_insights?.used ?? 0)} limit={usage.ai_insights?.limit ?? null} />
            <UsageBar label="JOLLY messages" used={Number(usage.jolly_messages?.used ?? 0)} limit={usage.jolly_messages?.limit ?? null} />
            <UsageBar label="Standard AI actions" used={Number(usage.standard_ai_actions?.used ?? 0)} limit={usage.standard_ai_actions?.limit ?? null} />
            <UsageBar label="Reports generated" used={Number(usage.reports?.used ?? 0)} limit={usage.reports?.limit ?? null} />
            <UsageBar label="Connected sites" used={sites.length} limit={plan.site_limit ?? null} />
          </div>
        </Card>
      </div>

      <Card className="mt-5" title="Insight credits" subtitle="Included credits are shown here for transparency; the active entitlement controls the limit.">
        <div className="grid gap-4 sm:grid-cols-3">
          <div><div className="text-xs text-muted">Included this month</div><div className="mt-1 text-2xl font-bold text-fg">{num(included)}</div></div>
          <div><div className="text-xs text-muted">Remaining</div><div className="mt-1 text-2xl font-bold text-fg">{num(remaining)}</div></div>
          <div><div className="text-xs text-muted">Used</div><div className="mt-1 text-2xl font-bold text-fg">{num(Math.max(0, included - remaining))}</div></div>
        </div>
      </Card>

      {!lifetime && (
        <Card className="mt-5" title="Stripe subscription plans" subtitle="Only Starter, Growth, Agency, and Agency Pro are available for recurring checkout.">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {upgrades.map((option: any) => (
              <button key={option.slug} type="button" onClick={() => setSelectedPlan(option.slug)} className={`rounded-xl border p-4 text-left ${selectedPlan === option.slug ? "border-brand ring-2 ring-brand/20" : "border-line"}`}>
                <div className="text-sm font-semibold text-fg">{option.name}</div>
                <div className="mt-1 text-lg font-bold text-fg">{money(option.price_monthly)}<span className="text-xs font-normal text-muted">/mo</span></div>
                <div className="mt-1 text-xs text-muted">{option.site_limit} connected sites</div>
              </button>
            ))}
          </div>
          <div className="mt-5 flex flex-wrap items-end gap-3">
            <label className="block min-w-40"><span className="mb-1.5 block text-xs font-semibold text-fg">Billing interval</span><select value={interval} onChange={(e) => setInterval(e.target.value as "monthly" | "yearly")} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg"><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></label>
            <Button onClick={checkout} disabled={busy || !selectedPlan || upgrades.length === 0}>{busy ? "Opening Stripe…" : "Continue to Stripe checkout"}</Button>
          </div>
        </Card>
      )}

      <Card className="mt-5" title="AppSumo lifetime tiers" subtitle="These tiers are redeemed through AppSumo and are not Stripe checkout products.">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          {appsumo.map((tier: any) => (
            <div key={tier.slug} className="rounded-xl border border-line bg-card2 p-4">
              <div className="text-sm font-semibold text-fg">{tier.name}</div>
              <div className="mt-1 text-lg font-bold text-fg">${tier.price}</div>
              <div className="mt-1 text-xs text-muted">{tier.sites} sites · {num(tier.monthly_ai_credits ?? 0)} monthly credits</div>
              <Badge className="mt-3" tone="slate">AppSumo redemption</Badge>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
