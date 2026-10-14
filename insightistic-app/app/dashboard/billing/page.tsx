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
import { Spinner } from "@/components/ui/Spinner";
import { initPaddle, openPaddleCheckout } from "@/lib/paddle";
import type { PaddleEnvironment } from "@/lib/paddle";
import { money0, num } from "@/lib/format";

// Fallback lineup — Growth is the featured "Best Value" plan. Live names and
// featured flags come from /public/plans once loaded; this constant wins only
// while that request is in flight or failed.
const PLANS = [
  { slug: "starter", name: "Starter", featured: false },
  { slug: "growth", name: "Growth — Best Value", featured: true },
  { slug: "agency", name: "Agency", featured: false },
  { slug: "agency_pro", name: "Agency Pro", featured: false },
];

type PlanOption = { slug: string; name: string };
type Phase = "idle" | "processing";

const POLL_TRIES = 15;
const POLL_INTERVAL_MS = 2000;

// Founding Angel LTD: one-time Paddle prices mapped to a permanent
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

/** The 501/code shape the API returns when the Paddle flag is off for an org. */
function isPaddleNotEnabled(e: any): boolean {
  return e?.status === 501 || e?.data?.code === "paddle_not_enabled" || e?.code === "paddle_not_enabled";
}

/**
 * Checkout errors split in two: API validation (readable, show as-is) and
 * Paddle.js internals throwing inside the overlay flow — those surface as
 * minified TypeErrors like "ton is not a function", which read as broken to
 * the user. Log the original for diagnostics and show something actionable.
 */
function checkoutErrorMessage(e: any): string {
  const raw = e?.message ? String(e.message) : "";
  if (/is not a function|is not a constructor|Unknown option parameter/i.test(raw)) {
    console.error("Paddle checkout failed", e);
    return "Checkout could not start — a payment-script error. Refresh the page and try again; if it keeps failing, contact support.";
  }
  return raw || "Checkout could not be started.";
}

export default function BillingPage() {
  const { data, loading, reload } = useApi<any>("/billing/subscription");
  const { data: plansData } = useApi<any>("/public/plans");
  const { sites } = useDashboard();
  const [plan, setPlan] = useState("growth");
  const [interval, setIntervalSel] = useState("monthly");
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [phase, setPhase] = useState<Phase>("idle");
  const [finishLabel, setFinishLabel] = useState("Finishing your subscription setup…");
  const [selectedFounderTier, setSelectedFounderTier] = useState<string | null>(null);
  const paidRef = useRef(false);

  // Preselect the plan passed from the marketing-site CTA (?plan=).
  useEffect(() => {
    const p = new URLSearchParams(window.location.search).get("plan");
    const selectedInterval = new URLSearchParams(window.location.search).get("interval");
    if (selectedInterval === "yearly" || selectedInterval === "monthly") setIntervalSel(selectedInterval);
    if (!p) return;

    if (PLANS.some((x) => x.slug === p)) {
      setPlan(p);
      return;
    }

    if (FOUNDER_TIER_SLUGS.has(p)) {
      setSelectedFounderTier(p);
      window.setTimeout(() => {
        document.getElementById("founder-lifetime")?.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 250);
    }
  }, []);

  // Live catalog names/featured flags win once loaded; the constant covers
  // the window while /public/plans is loading or failed.
  const planOptions: PlanOption[] = (() => {
    const live = plansData?.plans;
    if (!Array.isArray(live) || live.length === 0) return PLANS;
    return live
      .filter((p: any) => p?.slug && p?.is_purchasable !== false)
      .map((p: any) => {
        const name = String(p.name ?? p.slug);
        return {
          slug: String(p.slug),
          name:
            p.is_featured && !name.includes("Best Value") ? `${name} — Best Value` : name,
        };
      });
  })();

  /**
   * Paddle webhook, not the checkout callback, makes a subscription real.
   * Poll until the org's subscription is projected as Paddle (or the plan
   * visibly changed), then refresh the page data (roadmap 22A §9).
   */
  async function finishSetup(beforePlanName: string | undefined) {
    setFinishLabel("Finishing your subscription setup…");
    setPhase("processing");
    for (let attempt = 0; attempt < POLL_TRIES; attempt++) {
      const d = await apiGet("/billing/subscription").catch(() => null);
      const name = d?.plan?.name;
      if (d?.subscription?.billing_provider === "paddle" || (name && name !== beforePlanName)) {
        setMsg(`You're on ${name || plan} — everything is active.`);
        setPhase("idle");
        setBusy(false);
        reload();
        return;
      }
      await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
    }
    setMsg("Payment received — your subscription is finishing setup. Refresh in a moment.");
    setPhase("idle");
    setBusy(false);
    reload();
  }

  async function checkout() {
    setBusy(true); setMsg(null);
    const beforePlanName = data?.plan?.name;
    try {
      let cfg: any;
      try {
        cfg = await apiPost("/billing/checkout-config", { plan, kind: interval });
      } catch (e: any) {
        // Organizations still billed through Stripe keep the legacy redirect.
        if (!isPaddleNotEnabled(e)) throw e;
        const r = await apiPost("/billing/checkout", { plan, interval });
        window.location.href = r.url;
        return;
      }

      const environment: PaddleEnvironment = cfg?.environment === "sandbox" ? "sandbox" : "production";
      await initPaddle(cfg.client_token, environment);
      paidRef.current = false;
      openPaddleCheckout({
        priceId: cfg.price_id,
        email: cfg.customer?.email,
        customData: cfg.custom_data,
        onSuccess: () => { paidRef.current = true; finishSetup(beforePlanName); },
        onClose: (completed) => {
          // A completed close is the success flow's business; only an
          // abandoned checkout resets the form (silently).
          if (completed || paidRef.current) return;
          setBusy(false);
          setPhase("idle");
        },
        onUnavailable: () => {
          setMsg("Checkout could not be loaded — the secure payment host is unreachable. Check your connection and try again.");
          setPhase("idle");
          setBusy(false);
        },
      });
    } catch (e: any) {
      setMsg(checkoutErrorMessage(e));
      setPhase("idle");
      setBusy(false);
    }
  }

  /**
   * One-time purchases (founder lifetime / credit packs) reuse the same
   * Paddle overlay, but the webhook grants entitlements/credits instead of
   * projecting a subscription — so the completion poll watches for the
   * lifetime grant appearing rather than billing_provider flipping.
   */
  async function oneTimeCheckout(planSlug: string, kind: "lifetime" | "credit_pack", creditPack?: string) {
    setBusy(true); setMsg(null);
    try {
      const payload = { plan: planSlug, kind, ...(creditPack ? { credit_pack: creditPack } : {}) };
      const cfg: any = await apiPost("/billing/checkout-config", payload);
      const environment: PaddleEnvironment = cfg?.environment === "sandbox" ? "sandbox" : "production";
      await initPaddle(cfg.client_token, environment);
      paidRef.current = false;
      openPaddleCheckout({
        priceId: cfg.price_id,
        email: cfg.customer?.email,
        customData: cfg.custom_data,
        onSuccess: () => { paidRef.current = true; finishOneTime(planSlug, kind); },
        onClose: (completed) => {
          if (completed || paidRef.current) return;
          setBusy(false);
          setPhase("idle");
        },
        onUnavailable: () => {
          setMsg("Checkout could not be loaded — the secure payment host is unreachable. Check your connection and try again.");
          setPhase("idle");
          setBusy(false);
        },
      });
    } catch (e: any) {
      setMsg(checkoutErrorMessage(e));
      setPhase("idle");
      setBusy(false);
    }
  }

  async function finishOneTime(planSlug: string, kind: "lifetime" | "credit_pack") {
    setFinishLabel("Finishing your lifetime setup…");
    setPhase("processing");

    // The subscription payload exposes no credit balance today, so there is
    // nothing observable to poll — say where the credits land instead of
    // inventing a number (they appear once the webhook fulfills the pack).
    if (kind === "credit_pack") {
      setMsg("Payment received — your credits land after checkout completes. Refresh in a moment if the balance hasn't moved.");
      setPhase("idle");
      setBusy(false);
      reload();
      return;
    }

    for (let attempt = 0; attempt < POLL_TRIES; attempt++) {
      const d = await apiGet("/billing/subscription").catch(() => null);
      const grant = lifetimeGrantOf(d);
      if (grant) {
        setMsg(`Lifetime ${grantTierLabel(grant.plan_slug!)} is active — welcome to the Founding Angels.`);
        setPhase("idle");
        setBusy(false);
        reload();
        return;
      }
      await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
    }
    setMsg("Payment received — your lifetime access is finishing setup. Refresh in a moment.");
    setPhase("idle");
    setBusy(false);
    reload();
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
  const hasSubscription = !!data.subscription?.stripe_subscription_id || !!provider;
  const lifetimeGrant = lifetimeGrantOf(data);
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

  return (
    <div>
      <PageHeader title="Billing" subtitle="Your plan, usage, and subscription." />

      <div className="grid gap-5 lg:grid-cols-2">
        <Card className="bg-gradient-to-br from-brand/[0.08] to-transparent">
          <div className="text-[11px] font-bold uppercase tracking-wide text-brand-700">Current plan</div>
          <div className="mt-1 flex items-baseline gap-2">
            <span className="text-3xl font-bold text-fg">{planObj.name || "Free trial"}</span>
            {planObj.price_monthly != null && <span className="text-sm text-muted">{money0(Number(planObj.price_monthly) / 100)}/mo</span>}
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
          <div className="mt-5 flex flex-wrap gap-2">
            <Button onClick={() => document.getElementById("change-plan")?.scrollIntoView({ behavior: "smooth" })}>Upgrade</Button>
            <Button variant="ghost" onClick={portal} disabled={busy}>Manage in portal</Button>
            {/* Paddle customer portal — the link of record. The button above
                mints a fresh session for active Paddle subscriptions; this
                link always reaches Paddle's portal (email lookup) even
                without a subscription. */}
            <a
              href="https://customer-portal.paddle.com/cpl_01m1ycy6ry01sg09dgnyt31pd1"
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex h-9 items-center rounded-xl px-4 text-sm font-medium text-brand-700 underline-offset-2 hover:underline"
            >
              Manage subscription ↗
            </a>
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
            <UsageBar label="AI insights" used={usage.ai_insights?.used ?? 0} limit={usage.ai_insights?.limit ?? null} />
            <UsageBar label="Reports generated" used={usage.reports?.used ?? 0} limit={usage.reports?.limit ?? null} />
            <UsageBar label="Sites connected" used={sites.length} limit={planObj.site_limit ?? null} />
          </div>
        </Card>
      </div>

      <Card className="mt-5" title="Insight Credits" subtitle="Monthly included credits refresh automatically; purchased credits do not expire.">
        <div className="grid gap-4 sm:grid-cols-3">
          <div><div className="text-xs text-muted">Monthly included remaining</div><div className="mt-1 text-2xl font-bold text-fg">{num(data.credits?.monthly_included?.remaining ?? 0)}</div></div>
          <div><div className="text-xs text-muted">Purchased remaining</div><div className="mt-1 text-2xl font-bold text-fg">{num(data.credits?.purchased?.remaining ?? 0)}</div></div>
          <div><div className="text-xs text-muted">Total usable</div><div className="mt-1 text-2xl font-bold text-fg">{num(data.credits?.total_usable ?? 0)}</div></div>
        </div>
        <p className="mt-3 text-xs text-muted">Next monthly reset: {data.credits?.next_reset_at ? new Date(data.credits.next_reset_at).toLocaleDateString() : "—"}. AI actions use included credits before purchased credits.</p>
      </Card>

      <Card id="change-plan" className="mt-5" title="Change plan">
        {msg && <p className="mb-3 text-sm text-warn">{msg}</p>}
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <span className="mb-1.5 block text-xs font-semibold text-fg">Plan</span>
            <select value={plan} onChange={(e) => setPlan(e.target.value)}
              className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand">
              {planOptions.map((p) => <option key={p.slug} value={p.slug}>{p.name}</option>)}
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
              <Button onClick={checkout} disabled={busy}>{busy ? "Opening checkout…" : "Continue to checkout"}</Button>
              <p className="mt-2 text-xs text-muted">Secure checkout via {provider === "stripe" ? "Stripe" : "Paddle"}.</p>
            </>
          )}
        </div>
      </Card>

      <Card id="founder-lifetime" className="mt-5" title="Founding Angel Plans"
        subtitle="One payment. Lifetime core access. Only 100 total licenses across all Angel tiers.">
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
        ) : (
          <div className="grid gap-4 sm:grid-cols-3">
            {founderTiers.map((t: { slug: string; name: string; price: number; limits: string }) => (
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
                  Buy {t.name} — ${t.price}
                </Button>
              </div>
            ))}
          </div>
        )}
        <p className="mt-3 text-xs text-muted">
          Lifetime access applies to the purchased core plan and feature family while Insightistic operates. Monthly allowances are shared across connected sites and do not roll over. See <Link href="/terms" className="underline hover:text-fg">the terms</Link> for details.
        </p>
      </Card>

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
          Credits power AI visibility runs, AI inference and SERP checks. Core syncs never consume credits.
        </p>
      </Card>
    </div>
  );
}
