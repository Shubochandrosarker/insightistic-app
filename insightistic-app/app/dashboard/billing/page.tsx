"use client";
import { useEffect, useRef, useState } from "react";
import { useApi } from "@/lib/useApi";
import { useDashboard } from "@/lib/dashboard";
import { apiGet, apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { initPaddle, openPaddleCheckout } from "@/lib/paddle";
import type { PaddleEnvironment } from "@/lib/paddle";
import { num } from "@/lib/format";

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

export default function BillingPage() {
  const { data, loading, reload } = useApi<any>("/billing/subscription");
  const { data: plansData } = useApi<any>("/public/plans");
  const { sites } = useDashboard();
  const [plan, setPlan] = useState("growth");
  const [interval, setIntervalSel] = useState("monthly");
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [phase, setPhase] = useState<Phase>("idle");
  const paidRef = useRef(false);

  // Preselect the plan passed from the marketing-site CTA (?plan=).
  useEffect(() => {
    const p = new URLSearchParams(window.location.search).get("plan");
    if (p && PLANS.some((x) => x.slug === p)) setPlan(p);
  }, []);

  // Live catalog names/featured flags win once loaded; the constant covers
  // the window while /public/plans is loading or failed.
  const planOptions: PlanOption[] = (() => {
    const live = plansData?.plans;
    if (!Array.isArray(live) || live.length === 0) return PLANS;
    return live
      .filter((p: any) => p?.slug)
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
      });
    } catch (e: any) {
      setMsg(e?.message || "Checkout could not be started.");
      setPhase("idle");
      setBusy(false);
    }
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
  const hasSubscription = !!data.subscription?.stripe_subscription_id || !!provider;

  return (
    <div>
      <PageHeader title="Billing" subtitle="Your plan, usage, and subscription." />

      <div className="grid gap-5 lg:grid-cols-2">
        <Card className="bg-gradient-to-br from-brand/[0.08] to-transparent">
          <div className="text-[11px] font-bold uppercase tracking-wide text-brand-700">Current plan</div>
          <div className="mt-1 flex items-baseline gap-2">
            <span className="text-3xl font-bold text-fg">{planObj.name || "Free trial"}</span>
            {planObj.price_monthly != null && <span className="text-sm text-muted">${planObj.price_monthly}/mo</span>}
          </div>
          <div className="mt-1 text-sm text-muted">
            Status: {data.organization?.status}
            {data.organization?.trial_ends_at && ` · trial ends ${new Date(data.organization.trial_ends_at).toLocaleDateString()}`}
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
            <Spinner label="Finishing your subscription setup…" />
          ) : (
            <>
              <Button onClick={checkout} disabled={busy}>{busy ? "Opening checkout…" : "Continue to checkout"}</Button>
              <p className="mt-2 text-xs text-muted">Secure checkout via {provider === "stripe" ? "Stripe" : "Paddle"}.</p>
            </>
          )}
        </div>
      </Card>
    </div>
  );
}
