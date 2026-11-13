"use client";
import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useApi } from "@/lib/useApi";
import { useDashboard } from "@/lib/dashboard";
import { apiGet, apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { money0, num } from "@/lib/format";
import { TRIAL_DAYS } from "@/lib/trial";

// Fallback lineup — Growth is the featured "Best Value" plan. Live names and
// featured flags come from /public/plans once loaded; this constant wins only
// while that request is in flight or failed.
const PLANS = [
  { slug: "starter", name: "Starter", featured: false },
  { slug: "growth", name: "Growth — Best Value", featured: true },
  { slug: "agency", name: "Agency", featured: false },
  { slug: "agency_pro", name: "Agency Pro", featured: false },
];

const RECURRING_PLAN_SLUGS = new Set(["starter", "growth", "agency", "agency_pro"]);
const UPGRADE_RANKS: Record<string, number> = {
  free: 0,
  starter: 10,
  angel_solo: 20,
  growth: 30,
  angel_studio: 40,
  agency: 50,
  angel_agency: 60,
  agency_pro: 70,
};

type PlanOption = { slug: string; name: string };
type Phase = "idle" | "processing";

const POLL_TRIES = 15;
const POLL_INTERVAL_MS = 2000;

// Founding Angel LTD: one-time prices mapped to a permanent
// entitlement grant — the webhook fulfills it, the checkout never creates a
// subscription. The campaign cap is read from the backend catalog response.
const FOUNDER_TIER_FALLBACK = [
  {
    slug: "angel_solo",
    name: "Angel Solo",
    price: 149,
    limits: "3 sites · 10k crawl pages/mo · 15 browser minutes · 150 keywords · 3,000 monthly credits",
  },
  {
    slug: "angel_studio",
    name: "Angel Studio",
    price: 299,
    limits: "7 sites · 40k crawl pages/mo · 60 browser minutes · 500 keywords · 8,000 monthly credits",
  },
  {
    slug: "angel_agency",
    name: "Angel Agency",
    price: 699,
    limits: "15 sites · 100k crawl pages/mo · 180 browser minutes · 1,500 keywords · 20,000 monthly credits",
  },
];

const FOUNDER_TIER_SLUGS = new Set(FOUNDER_TIER_FALLBACK.map((tier) => tier.slug));

// Insight Credit packs: one-time purchases granted to the credit ledger by
// the webhook (credits arrive in price metadata). Core syncs never consume
// credits — see /terms for the fine print.
const CREDIT_PACK_FALLBACK = [
    { slug: "credits_5000", credits: 5000, price: 15 },
    { slug: "credits_20000", credits: 20000, price: 49 },
    { slug: "credits_50000", credits: 50000, price: 99 },
];

/** The lifetime grant rides on the subscription payload (or alongside it). */
function lifetimeGrantOf(d: any): { active?: boolean; plan_slug?: string } | null {
  const g = d?.lifetime_grant ?? d?.subscription?.lifetime_grant;
  return g?.active && g?.plan_slug ? g : null;
}

/** Tier number from a grant's plan slug ("founder_tier_2" -> "Tier 2"). */
function grantTierLabel(planSlug: string): string {
  const legacy = planSlug.match(/^founder_tier_(\d+)$/);
  if (legacy) return `Founder Tier ${legacy[1]}`;
  return planSlug.replace(/^angel_/, "Angel ").replace(/_/g, " ");
}

function UsageBar({ label, used, limit }: { label: string; used: number; limit: number | null }) {
  const ratio = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between text-sm">
        <span className="text-muted">{label}</span>
        <span className="font-semibold text-fg">{num(used)} / {limit ?? "∞"}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-black/10 dark:bg-white/10">
        <div className="h-full rounded-full bg-gradient-to-r from-brand2 to-brand" style={{ width: `${ratio}%` }} />
      </div>
    </div>
  );
}

export default function BillingPage() {
  const { data, loading, error, reload } = useApi<any>("/billing/subscription");
  const { data: plansData } = useApi<any>("/public/plans");
  // Provider-agnostic summary: next renewal, invoices, portal availability.
  const { data: summary } = useApi<any>("/billing/summary");
  const { sites } = useDashboard();
  const [plan, setPlan] = useState("growth");
  const [interval, setIntervalSel] = useState("monthly");
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [phase, setPhase] = useState<Phase>("idle");
  const [finishLabel, setFinishLabel] = useState("Finishing your subscription setup…");
  const [selectedFounderTier, setSelectedFounderTier] = useState<string | null>(null);
  const [checkoutReturn, setCheckoutReturn] = useState<{ status: "success" | "cancel"; provider: string | null; kind: string | null } | null>(null);
  const trialDays: number = Number(data?.trial?.days ?? TRIAL_DAYS);
  const trialEligible: boolean = data?.trial?.eligible === true;
  const checkoutReturnHandled = useRef(false);

  // Preselect the plan passed from the marketing-site CTA (?plan=).
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const p = params.get("plan");
    const selectedInterval = params.get("interval");
    const result = params.get("checkout");
    if (result === "success" || result === "cancel") {
      setCheckoutReturn({ status: result, provider: params.get("provider"), kind: params.get("kind") });
    }
    // Stripe credit-pack checkout returns here with ?credits=success|cancel.
    if (params.get("credits") === "success") {
      setMsg("Payment received — your credits land after checkout completes. Refresh in a moment if the balance hasn't moved yet.");
    }
    if (params.get("credits") === "cancel") {
      setMsg("Checkout was cancelled. No payment was taken; you can try again.");
    }
    if (selectedInterval === "yearly" || selectedInterval === "monthly") setIntervalSel(selectedInterval);
    if (!p) return;

    if (PLANS.some((x) => x.slug === p)) {
      setPlan(p);
      return;
    }

    if (FOUNDER_TIER_SLUGS.has(p)) {
      setSelectedFounderTier(p);
      window.setTimeout(() => {
        const target = document.getElementById("founder-lifetime");
        if (typeof target?.scrollIntoView === "function") {
          target.scrollIntoView({ behavior: "smooth", block: "start" });
        }
      }, 250);
    }
  }, []);

  /** Regular plans start as a no-card trial (TRIAL_DAYS, from the API) with no checkout. */
  async function startTrial() {
    setBusy(true); setMsg(null);
    try {
      const r = await apiPost("/billing/start-trial", { plan });
      const ends = r?.organization?.trial_ends_at ? new Date(r.organization.trial_ends_at).toLocaleDateString() : null;
      setMsg(`Your ${trialDays}-day free trial is active${ends ? ` until ${ends}` : ""} — no payment needed.`);
      reload();
    } catch (e: any) {
      setMsg(e?.data?.message || e?.message || "The trial could not be started.");
    } finally {
      setBusy(false);
    }
  }

  async function stripeCheckout(checkoutPlan = plan, checkoutKind = interval) {
    setBusy(true); setMsg(null);
    try {
      const r: any = await apiPost("/billing/stripe/checkout", { plan: checkoutPlan, kind: checkoutKind });
      window.location.href = r.url;
    } catch (e: any) {
      setMsg(e?.data?.message || e?.message || "Checkout could not be started. Try again.");
      setBusy(false);
    }
  }

  /**
   * One-time purchases are Stripe-only: founder lifetime goes through the
   * same checkout endpoint (mode=payment server-side), credit packs through
   * the dedicated POST /billing/credits/checkout. Entitlements and credits
   * are granted by the webhook after payment, never by the redirect.
   */
  async function oneTimeCheckout(planSlug: string, kind: "lifetime" | "credit_pack", creditPack?: string) {
    if (kind === "lifetime") return stripeCheckout(planSlug, kind);
    setBusy(true); setMsg(null);
    try {
      const r: any = await apiPost("/billing/credits/checkout", { pack: creditPack });
      window.location.href = r.url;
    } catch (e: any) {
      setMsg(e?.data?.message || e?.message || "Checkout could not be started.");
      setBusy(false);
    }
  }

  async function finishStripeSetup(kind: string) {
    setFinishLabel(kind === "lifetime" ? "Confirming your lifetime purchase…" : "Confirming your subscription…");
    setPhase("processing");
    for (let attempt = 0; attempt < POLL_TRIES; attempt++) {
      const d = await apiGet("/billing/subscription").catch(() => null);
      if (kind === "lifetime" && lifetimeGrantOf(d)) {
        setMsg(`Lifetime ${grantTierLabel(lifetimeGrantOf(d)!.plan_slug!)} is active — welcome to the Founding Angels.`);
        setPhase("idle"); setBusy(false); reload(); return;
      }
      if (kind !== "lifetime" && d?.subscription?.billing_provider === "stripe" && ["active", "trialing"].includes(String(d.subscription.status))) {
        setMsg(`Your ${d?.plan?.name || "subscription"} is active.`);
        setPhase("idle"); setBusy(false); reload(); return;
      }
      await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
    }
    setMsg("Payment received — account activation is finishing. Refresh in a moment if the plan has not appeared yet.");
    setCheckoutReturn(null);
    setPhase("idle"); setBusy(false); reload();
  }

  async function portal() {
    setBusy(true); setMsg(null);
    try { const r = await apiPost("/billing/portal"); window.location.href = r.url; }
    catch (e: any) { setMsg(e.message); setBusy(false); }
  }
  async function cancelSub() {
    if (!window.confirm("Cancel your subscription? You keep full access until the end of the current billing period.")) return;
    setBusy(true); setMsg(null);
    try { const r = await apiPost("/billing/cancel"); setMsg(r.message || "Cancellation scheduled."); reload(); }
    catch (e: any) { setMsg(e.message); }
    finally { setBusy(false); }
  }
  async function reactivateSub() {
    setBusy(true); setMsg(null);
    try { const r = await apiPost("/billing/reactivate"); setMsg(r.message || "Subscription reactivated."); reload(); }
    catch (e: any) { setMsg(e.message); }
    finally { setBusy(false); }
  }

  // Keep the selected checkout plan valid when the authenticated account or
  // live catalog arrives after the initial render.
  useEffect(() => {
    if (!data) return;
    const currentSlug = String(lifetimeGrantOf(data)?.plan_slug || data.plan?.slug || "free");
    const currentRank = UPGRADE_RANKS[currentSlug] ?? 0;
    const live = Array.isArray(plansData?.plans) && plansData.plans.length ? plansData.plans : PLANS;
    const options = live.filter((p: any) => {
      const slug = String(p?.slug || "");
      return RECURRING_PLAN_SLUGS.has(slug)
        && p?.is_purchasable !== false
        && (UPGRADE_RANKS[slug] ?? 0) > currentRank;
    });
    if (options.length && !options.some((p: any) => String(p.slug) === plan)) setPlan(String(options[0].slug));
  }, [data, plansData, plan]);

  useEffect(() => {
    if (!data || !checkoutReturn || checkoutReturnHandled.current) return;
    checkoutReturnHandled.current = true;
    if (checkoutReturn.status === "cancel") {
      setMsg("Checkout was cancelled. No payment was taken; you can try again.");
      return;
    }
    if (checkoutReturn.provider === "stripe") {
      void finishStripeSetup(checkoutReturn.kind || "subscription");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, checkoutReturn]);

  // The subscription endpoint is owner/admin-only — non-privileged members
  // and transient failures must land on a retry card, not an eternal spinner.
  if ((loading || !data) && error) {
    return (
      <div>
        <PageHeader title="Billing" subtitle="Your plan, subscription and invoices." />
        <ErrorState message={error} onRetry={reload} />
      </div>
    );
  }
  if (loading || !data) return <Spinner />;
  const usage = data.usage || {};
  const planObj = data.plan || {};
  const provider: string | null =
    data.subscription?.billing_provider ?? (data.subscription?.stripe_subscription_id ? "stripe" : null);
  const cancelPending =
    !!data.subscription?.cancel_at_period_end || data.subscription?.scheduled_change_action === "cancel";
  const trialStatusLabel =
    data.organization?.status === "trialing"
      ? "trial ends"
      : data.organization?.status === "expired"
        ? "trial ended"
        : null;
  const invoices: any[] = summary?.invoices ?? [];
  const hasSubscription = !!data.subscription?.stripe_subscription_id || !!provider;
  const lifetimeGrant = lifetimeGrantOf(data);
  const ltdIntent = Boolean(selectedFounderTier && !lifetimeGrant && checkoutReturn?.status !== "success");
  const ltdPaymentPending = Boolean(selectedFounderTier && !lifetimeGrant && checkoutReturn?.status === "success");
  const currentSlug = String(lifetimeGrant?.plan_slug || planObj.slug || "free");
  const currentRank = UPGRADE_RANKS[currentSlug] ?? 0;

  // The API is the source of truth, but only the four recurring products can
  // be opened through the subscription checkout. Filter to plans strictly
  // above the current entitlement so a lifetime Angel customer never sees a
  // lower Growth/Starter choice as an apparent upgrade.
  const liveRecurring = Array.isArray(plansData?.plans) && plansData.plans.length
    ? plansData.plans
    : PLANS;
  const planOptions: PlanOption[] = liveRecurring
    .filter((p: any) => {
      const slug = String(p?.slug || "");
      return RECURRING_PLAN_SLUGS.has(slug)
        && p?.is_purchasable !== false
        && (UPGRADE_RANKS[slug] ?? 0) > currentRank;
    })
    .map((p: any) => {
      const name = String(p.name ?? p.slug);
      return {
        slug: String(p.slug),
        name: p.is_featured && !name.includes("Best Value") ? `${name} — Best Value` : name,
      };
    });
  const selectedPlanAvailable = planOptions.some((p) => p.slug === plan);
  const isLifetimePlan = Boolean(lifetimeGrant) || currentSlug.startsWith("angel_") || planObj.limits?.billing_model === "lifetime";
  const monthlyCreditsGranted = Number(data.credits?.monthly_included?.granted ?? planObj.limits?.monthly_ai_credits ?? 0);
  const monthlyCreditsRemaining = Number(data.credits?.monthly_included?.remaining ?? 0);
  const monthlyCreditsUsed = Math.max(0, monthlyCreditsGranted - monthlyCreditsRemaining);

  const campaign = plansData?.ltd?.campaign;
  const soldOut = campaign?.sold_out === true;
  const founderTiers = Array.isArray(plansData?.ltd?.plans) && plansData.ltd.plans.length
    ? plansData.ltd.plans.map((tier: any) => {
        const limits = tier.limits ?? {};
        return {
          slug: String(tier.slug),
          name: String(tier.name),
          price: Number(tier.price),
          limits: `${Number(tier.sites).toLocaleString()} sites · ${Number(limits.crawl_pages_monthly ?? 0).toLocaleString()} crawl pages/mo · ${Number(limits.browser_rendering_minutes_monthly ?? 0)} browser minutes · ${Number(limits.tracked_keywords ?? 0).toLocaleString()} keywords · ${Number(tier.monthly_ai_credits).toLocaleString()} monthly credits`,
        };
      })
    : FOUNDER_TIER_FALLBACK;
  const creditPacks = Array.isArray(plansData?.credit_packs) && plansData.credit_packs.length
    ? plansData.credit_packs.map((pack: any) => ({
        slug: String(pack.slug),
        credits: Number(pack.credits),
        price: Number(pack.price_minor) / 100,
      }))
    : CREDIT_PACK_FALLBACK;
  const appsumoTiers = Array.isArray(plansData?.appsumo?.plans) ? plansData.appsumo.plans : [];
  const currentAppSumoTier = appsumoTiers.find((tier: any) => String(tier.slug) === currentSlug);

  return (
    <div>
      <PageHeader title="Billing" subtitle="Your plan, usage, and subscription." />

      <div className="grid gap-5 lg:grid-cols-2">
        <Card className="bg-gradient-to-br from-brand/[0.08] to-transparent">
          <div className="text-[11px] font-bold uppercase tracking-wide text-brand-700">Current plan</div>
          <div className="mt-1 flex items-baseline gap-2">
            <span className="text-3xl font-bold text-fg">{planObj.name || "Free trial"}</span>
            {planObj.price_monthly != null && <span className="text-sm text-muted">{money0(Number(planObj.price_monthly) / 100)} {isLifetimePlan ? "one time · lifetime" : "/mo"}</span>}
          </div>
          <div className="mt-1 text-sm text-muted">
            Status: {data.organization?.status}
            {trialStatusLabel && data.organization?.trial_ends_at && ` · ${trialStatusLabel} ${new Date(data.organization.trial_ends_at).toLocaleDateString()}`}
          </div>
          {cancelPending && (
            <p className="mt-2 rounded-lg border border-warn/30 bg-warn/10 px-3 py-2 text-xs text-warn">
              Cancellation scheduled — access continues until
              {data.subscription?.current_period_end
                ? ` ${new Date(data.subscription.current_period_end).toLocaleDateString()}.`
                : " the end of the billing period."}
            </p>
          )}
          {summary?.provider && (
            <div className="mt-1 text-sm text-muted">
              Billed via Stripe
              {summary.next_renewal_at && !cancelPending && ` · renews ${new Date(summary.next_renewal_at).toLocaleDateString()}`}
            </div>
          )}
          <div className="mt-5 flex flex-wrap gap-2">
            <Button onClick={() => document.getElementById("change-plan")?.scrollIntoView({ behavior: "smooth" })}>Upgrade</Button>
            <Button variant="ghost" onClick={portal} disabled={busy}>Manage in portal</Button>
            {hasSubscription && !cancelPending && (
              <Button variant="danger" onClick={cancelSub} disabled={busy}>Cancel subscription</Button>
            )}
            {/* Paddle cancellations are final — a new checkout is the way back. */}
            {cancelPending && provider !== "paddle" && (
              <Button variant="soft" onClick={reactivateSub} disabled={busy}>Reactivate</Button>
            )}
          </div>
        </Card>

        <Card title="Usage this period">
          <div className="space-y-4">
            <UsageBar label="AI insight runs" used={usage.ai_insights?.used ?? 0} limit={usage.ai_insights?.limit ?? null} />
            <UsageBar label="Reports generated" used={usage.reports?.used ?? 0} limit={usage.reports?.limit ?? null} />
            <UsageBar label="Sites connected" used={sites.length} limit={planObj.site_limit ?? null} />
          </div>
        </Card>
      </div>

      <Card className="mt-5" title="Insight Credits" subtitle="Monthly included credits refresh automatically; purchased credits do not expire.">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div><div className="text-xs text-muted">Included this month</div><div className="mt-1 text-2xl font-bold text-fg">{num(monthlyCreditsGranted)}</div></div>
          <div><div className="text-xs text-muted">Included remaining</div><div className="mt-1 text-2xl font-bold text-fg">{num(monthlyCreditsRemaining)}</div></div>
          <div><div className="text-xs text-muted">Used this month</div><div className="mt-1 text-2xl font-bold text-fg">{num(monthlyCreditsUsed)}</div></div>
          <div><div className="text-xs text-muted">Purchased remaining</div><div className="mt-1 text-2xl font-bold text-fg">{num(data.credits?.purchased?.remaining ?? 0)}</div></div>
        </div>
        <p className="mt-3 text-xs text-muted">Total usable now: {num(data.credits?.total_usable ?? monthlyCreditsRemaining)}. Next monthly reset: {data.credits?.next_reset_at ? new Date(data.credits.next_reset_at).toLocaleDateString() : "—"}. AI actions use included credits before purchased credits.</p>
      </Card>

      {currentAppSumoTier && (
        <Card className="mt-5" title="AppSumo lifetime entitlement" subtitle="Your AppSumo license controls the capacity below; AppSumo manages the one-time purchase and license lifecycle.">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><div className="text-xs text-muted">Connected sites</div><div className="mt-1 text-xl font-bold text-fg">{num(Number(currentAppSumoTier.sites))}</div></div>
            <div><div className="text-xs text-muted">Page crawling / month</div><div className="mt-1 text-xl font-bold text-fg">{num(Number(currentAppSumoTier.limits?.crawl_pages_monthly ?? 0))} pages</div></div>
            <div><div className="text-xs text-muted">Browser rendering / month</div><div className="mt-1 text-xl font-bold text-fg">{num(Number(currentAppSumoTier.limits?.browser_rendering_minutes_monthly ?? 0))} min</div></div>
            <div><div className="text-xs text-muted">Weekly tracked keywords</div><div className="mt-1 text-xl font-bold text-fg">{num(Number(currentAppSumoTier.limits?.tracked_keywords ?? 0))}</div></div>
            <div><div className="text-xs text-muted">AI actions / month</div><div className="mt-1 text-xl font-bold text-fg">{num(Number(currentAppSumoTier.standard_ai_requests ?? 0))}</div></div>
            <div><div className="text-xs text-muted">Included Insight Credits / month</div><div className="mt-1 text-xl font-bold text-fg">{num(Number(currentAppSumoTier.monthly_ai_credits ?? 0))}</div></div>
            <div><div className="text-xs text-muted">Reports generated / month</div><div className="mt-1 text-xl font-bold text-fg">{num(Number(currentAppSumoTier.limits?.client_ready_reports_monthly ?? 0))}</div></div>
            <div><div className="text-xs text-muted">Active report schedules</div><div className="mt-1 text-xl font-bold text-fg">{num(Number(currentAppSumoTier.limits?.scheduled_report_limit ?? 0))}</div></div>
            <div><div className="text-xs text-muted">Team members</div><div className="mt-1 text-xl font-bold text-fg">{Number(currentAppSumoTier.team_member_limit) === 0 ? "Unlimited" : `${num(Number(currentAppSumoTier.team_member_limit))} added`}</div></div>
            <div><div className="text-xs text-muted">Added member cost</div><div className="mt-1 text-xl font-bold text-fg">{num(Number(currentAppSumoTier.team_member_add_credits ?? 10))} credits</div></div>
          </div>
          <p className="mt-3 text-xs text-muted">Local SEO rank tracking is available by market and device. Tier 3 and above also include AI competitor visibility insights; rank checks and other variable work use your Insight Credit wallet. Monthly allowances are shared across connected sites and reset monthly.</p>
        </Card>
      )}

      {!ltdIntent && <Card id="change-plan" className="mt-5" title="Change plan">
        {msg && <p className="mb-3 text-sm text-warn">{msg}</p>}
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <span className="mb-1.5 block text-xs font-semibold text-fg">Plan</span>
            <select value={plan} onChange={(e) => setPlan(e.target.value)}
              className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand">
              {planOptions.length === 0
                ? <option value="">No higher plan is available</option>
                : planOptions.map((p) => <option key={p.slug} value={p.slug}>{p.name}</option>)}
            </select>
          </label>
          <label className="block">
            <span className="mb-1.5 block text-xs font-semibold text-fg">Billing interval</span>
            <select value={interval} onChange={(e) => setIntervalSel(e.target.value)}
              className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand">
              <option value="monthly">Monthly</option>
              <option value="yearly">Yearly</option>
            </select>
          </label>
        </div>
        <div className="mt-4">
          {phase === "processing" ? (
            <Spinner label={finishLabel} />
          ) : (
            <>
              {trialEligible && planOptions.length > 0 ? (
                <>
                  <Button onClick={startTrial} disabled={busy || !selectedPlanAvailable}>{busy ? "Starting trial…" : `Start ${trialDays}-day free trial`}</Button>
                  <p className="mt-2 text-xs text-muted">No payment required to start. Choose a paid plan any time before the trial ends.</p>
                </>
              ) : (
                <>
                  <Button onClick={() => stripeCheckout()} disabled={busy || !selectedPlanAvailable}>{busy ? "Opening checkout…" : planOptions.length ? "Continue to checkout" : "No upgrade available"}</Button>
                  <p className="mt-2 text-xs text-muted">Secure checkout via Stripe. Subscription charges are not refundable — cancel any time before your renewal date to stop the next charge.</p>
                </>
              )}
            </>
          )}
        </div>
      </Card>}

      {invoices.length > 0 && (
        <Card className="mt-5" title="Invoices">
          <ul className="divide-y divide-line text-sm">
            {invoices.map((inv: any) => (
              <li key={inv.id} className="flex items-center justify-between py-2">
                <span className="text-fg">{inv.date ? new Date(inv.date).toLocaleDateString() : "—"} · {money0(Number(inv.total_minor ?? 0) / 100)} <Badge>{inv.status}</Badge></span>
                {inv.url ? <a href={inv.url} target="_blank" rel="noopener noreferrer" className="text-brand-700 hover:underline">View ↗</a> : <span className="text-muted">—</span>}
              </li>
            ))}
          </ul>
        </Card>
      )}

      {/* Founding Angel card: shown to holders (lifetimeGrant), or when a user
          arrives with an Angel tier selected. While APPSUMO_AFFILIATE_URL is
          empty, proxy.ts 302s new ?plan=angel_* arrivals to /pricing (payment
          returns with ?checkout= still land here). */}
      {(selectedFounderTier || lifetimeGrant) && (
      <Card id="founder-lifetime" className="mt-5" title="Founding Angel Plans"
        subtitle="One payment. Lifetime core access. Only 100 total licenses across all Angel tiers.">
        {msg && <p className="mb-3 rounded-lg border border-warn/30 bg-warn/10 px-3 py-2 text-sm text-warn">{msg}</p>}
        {soldOut && <p className="mb-3 rounded-lg border border-line bg-card2 px-3 py-2 text-sm text-muted">Founding Angel Plans — Sold Out. Our first 100 Founding Angel licenses have been claimed. You can still choose a regular subscription or buy Insight Credits.</p>}
        {selectedFounderTier && !lifetimeGrant && (
          <p className="mb-3 rounded-lg border border-brand/30 bg-brand/10 px-3 py-2 text-sm font-medium text-brand-700">
            {founderTiers.find((tier: { slug: string; name: string }) => tier.slug === selectedFounderTier)?.name ?? "Lifetime tier"} selected from the lifetime deal page.
          </p>
        )}
        {lifetimeGrant ? (
          <div>
            <Badge tone="green" dot>Lifetime {grantTierLabel(lifetimeGrant.plan_slug!)} active</Badge>
            <p className="mt-3 text-sm text-muted">
              Your Founding Angel tier is a permanent entitlement — no further purchase needed. Credit packs below still
              top up AI usage anytime.
            </p>
          </div>
        ) : ltdPaymentPending ? (
          <div className="rounded-xl border border-brand/20 bg-brand/5 p-5">
            <Spinner label="Confirming your payment and activating lifetime access…" />
            <p className="mt-3 text-sm text-muted">Do not pay again. Your account will update automatically after Stripe confirms the payment.</p>
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-3">
            {founderTiers
              .filter((t: { slug: string }) => (UPGRADE_RANKS[t.slug] ?? 0) > currentRank)
              .map((t: { slug: string; name: string; price: number; limits: string }) => (
              <div
                key={t.slug}
                className={`flex flex-col rounded-xl border bg-card2 p-4 ${
                  selectedFounderTier === t.slug
                    ? "border-brand shadow-lg shadow-brand/10 ring-2 ring-brand/20"
                    : "border-line"
                }`}
              >
                <div className="flex items-baseline justify-between">
                  <span className="text-sm font-semibold text-fg">{t.name}</span>
                  <span className="text-lg font-bold text-fg">${t.price}</span>
                </div>
                <p className="mt-2 flex-1 text-xs leading-relaxed text-muted">{t.limits}</p>
                <Button
                  variant="soft"
                  className="mt-3"
                  disabled={busy || soldOut}
                  onClick={() => oneTimeCheckout(t.slug, "lifetime")}
                >
                  Pay ${t.price}
                </Button>
              </div>
              ))}
            {founderTiers.every((t: { slug: string }) => (UPGRADE_RANKS[t.slug] ?? 0) <= currentRank) && (
              <p className="text-sm text-muted">No higher Founding Angel tier is available for this account.</p>
            )}
          </div>
        )}
        <p className="mt-3 text-xs text-muted">
          Lifetime access applies to the purchased core plan and feature family while Insightistic operates. Monthly allowances are shared across connected sites and do not roll over. No free trial; 30-day money-back guarantee from the purchase date. See <Link href="/terms" className="underline hover:text-fg">the terms</Link> for details.
        </p>
      </Card>

      )}

      <Card id="credit-packs" className="mt-5" title="Insight credit packs"
        subtitle="One-time top-ups — they don't expire while the service operates.">
        <div className="grid gap-4 sm:grid-cols-3">
          {creditPacks.map((p: { slug: string; credits: number; price: number }) => (
            <div key={p.credits} className="flex flex-col rounded-xl border border-line bg-card2 p-4">
              <div className="flex items-baseline justify-between">
                <span className="text-sm font-semibold text-fg">{num(p.credits)} credits</span>
                <span className="text-lg font-bold text-fg">${p.price}</span>
              </div>
              <Button
                variant="soft"
                className="mt-3"
                disabled={busy}
                onClick={() => oneTimeCheckout("insight_credits", "credit_pack", p.slug)}
              >
                Buy {num(p.credits)} credits — ${p.price}
              </Button>
            </div>
          ))}
        </div>
        <p className="mt-3 text-xs text-muted">
          Credits power AI visibility runs, AI inference and SERP checks. Core syncs never consume credits. Credit packs are non-refundable.
        </p>
      </Card>
    </div>
  );
}
