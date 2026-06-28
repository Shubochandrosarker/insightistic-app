"use client";
import { useEffect, useState } from "react";
import { useApi } from "@/lib/useApi";
import { useDashboard } from "@/lib/dashboard";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";

const PLANS = [
  { slug: "starter", name: "Starter" },
  { slug: "growth", name: "Growth" },
  { slug: "business", name: "Business" },
  { slug: "agency", name: "Agency" },
  { slug: "agency_pro", name: "Agency Pro" },
];

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
  const { data, loading } = useApi<any>("/billing/subscription");
  const { sites } = useDashboard();
  const [plan, setPlan] = useState("growth");
  const [interval, setIntervalSel] = useState("monthly");
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Preselect the plan passed from the marketing-site CTA (?plan=).
  useEffect(() => {
    const p = new URLSearchParams(window.location.search).get("plan");
    if (p && PLANS.some((x) => x.slug === p)) setPlan(p);
  }, []);

  async function checkout() {
    setBusy(true); setMsg(null);
    try { const r = await apiPost("/billing/checkout", { plan, interval }); window.location.href = r.url; }
    catch (e: any) { setMsg(e.message); setBusy(false); }
  }
  async function portal() {
    setBusy(true); setMsg(null);
    try { const r = await apiPost("/billing/portal"); window.location.href = r.url; }
    catch (e: any) { setMsg(e.message); setBusy(false); }
  }

  if (loading || !data) return <Spinner />;
  const usage = data.usage || {};
  const planObj = data.plan || {};

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
          <div className="mt-5 flex gap-2">
            <Button onClick={() => document.getElementById("change-plan")?.scrollIntoView({ behavior: "smooth" })}>Upgrade</Button>
            <Button variant="ghost" onClick={portal} disabled={busy}>Manage in portal</Button>
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
              {PLANS.map((p) => <option key={p.slug} value={p.slug}>{p.name}</option>)}
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
          <Button onClick={checkout} disabled={busy}>{busy ? "Redirecting…" : "Continue to checkout"}</Button>
          <p className="mt-2 text-xs text-muted">Secure checkout via Stripe.</p>
        </div>
      </Card>
    </div>
  );
}
