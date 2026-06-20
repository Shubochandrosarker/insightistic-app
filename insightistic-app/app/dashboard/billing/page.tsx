"use client";
import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";

const PLANS = [
  { slug: "starter", name: "Starter" },
  { slug: "growth", name: "Growth" },
  { slug: "business", name: "Business" },
  { slug: "agency", name: "Agency" },
  { slug: "agency_pro", name: "Agency Pro" },
];

export default function BillingPage() {
  const { data, loading } = useApi<any>("/billing/subscription");
  const [plan, setPlan] = useState("growth");
  const [interval, setInterval] = useState("monthly");
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function checkout() {
    setBusy(true); setMsg(null);
    try { const r = await apiPost("/billing/checkout", { plan, interval }); window.location.href = r.url; }
    catch (e: any) { setMsg(e.data?.message || e.message); setBusy(false); }
  }
  async function portal() {
    setBusy(true); setMsg(null);
    try { const r = await apiPost("/billing/portal"); window.location.href = r.url; }
    catch (e: any) { setMsg(e.data?.message || e.message); setBusy(false); }
  }

  if (loading || !data) return <Spinner />;
  const usage = data.usage || {};

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card title="Current plan">
        <div className="text-2xl font-semibold text-white">{data.plan?.name || "No plan"}</div>
        <div className="mt-1 text-sm text-slate-400">Status: {data.organization?.status}</div>
        {data.organization?.trial_ends_at && (
          <div className="mt-1 text-sm text-slate-400">Trial ends: {new Date(data.organization.trial_ends_at).toLocaleDateString()}</div>
        )}
        <div className="mt-4 space-y-1 text-sm text-slate-300">
          <div>AI insights: {num(usage.ai_insights?.used)} / {usage.ai_insights?.limit ?? "—"}</div>
          <div>Reports: {num(usage.reports?.used)} / {usage.reports?.limit ?? "—"}</div>
        </div>
        <div className="mt-5">
          <Button variant="ghost" onClick={portal} disabled={busy}>Manage billing</Button>
        </div>
      </Card>

      <Card title="Change plan">
        {msg && <p className="mb-3 text-sm text-amber-300">{msg}</p>}
        <label className="mb-1 block text-sm text-slate-300">Plan</label>
        <select value={plan} onChange={(e) => setPlan(e.target.value)} className="mb-3 w-full rounded-lg border border-line bg-ink px-3 py-2 text-sm text-slate-100">
          {PLANS.map((p) => <option key={p.slug} value={p.slug}>{p.name}</option>)}
        </select>
        <label className="mb-1 block text-sm text-slate-300">Billing interval</label>
        <select value={interval} onChange={(e) => setInterval(e.target.value)} className="mb-4 w-full rounded-lg border border-line bg-ink px-3 py-2 text-sm text-slate-100">
          <option value="monthly">Monthly</option>
          <option value="yearly">Yearly</option>
        </select>
        <Button onClick={checkout} disabled={busy}>{busy ? "Redirecting…" : "Continue to checkout"}</Button>
        <p className="mt-3 text-xs text-slate-500">Secure checkout via Stripe.</p>
      </Card>
    </div>
  );
}
