"use client";

import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiGet, apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";

export default function AdminBilling() {
  const { data: catalogData, loading: catalogLoading } = useApi<any>("/public/plans");
  const [organizationId, setOrganizationId] = useState("");
  const [wallet, setWallet] = useState<any>(null);
  const [form, setForm] = useState({ action: "grant", amount: "1000", reason: "" });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  async function loadWallet() {
    if (!organizationId.trim()) return;
    setMessage(null);
    try {
      setWallet(await apiGet("/admin/billing/organizations/" + encodeURIComponent(organizationId.trim()) + "/credits"));
    } catch (e: any) {
      setMessage(e.message || "Could not load the organization wallet.");
    }
  }

  async function adjust() {
    if (!organizationId.trim() || !form.reason.trim()) return;
    setBusy(true);
    setMessage(null);
    try {
      await apiPost("/admin/billing/organizations/" + encodeURIComponent(organizationId.trim()) + "/credits/adjust", {
        action: form.action,
        amount: Number(form.amount),
        reason: form.reason.trim(),
      });
      setMessage("Credit adjustment recorded.");
      setForm({ ...form, reason: "" });
      await loadWallet();
    } catch (e: any) {
      setMessage(e.message || "Could not record the adjustment.");
    } finally {
      setBusy(false);
    }
  }

  if (catalogLoading) return <Spinner />;

  const recurring = (catalogData?.plans || []).filter((p: any) => ["starter", "growth", "agency", "agency_pro"].includes(p.slug));
  const appsumo = catalogData?.appsumo?.plans || [];
  const loadedWallet = wallet?.wallet;

  return (
    <div>
      <PageHeader title="Billing operations" subtitle="Review the active Stripe/AppSumo catalog and perform audited wallet adjustments." />

      <Card title="Current catalog" subtitle="Only these offers may appear in public pricing or checkout.">
        <div className="grid gap-4 md:grid-cols-2">
          <div>
            <h3 className="text-sm font-semibold text-fg">Stripe subscriptions</h3>
            <p className="mt-2 text-sm text-muted">{recurring.map((p: any) => p.name).join(" · ") || "Not seeded"}</p>
          </div>
          <div>
            <h3 className="text-sm font-semibold text-fg">AppSumo lifetime entitlements</h3>
            <p className="mt-2 text-sm text-muted">{appsumo.map((p: any) => p.name).join(" · ") || "Not seeded"}</p>
          </div>
        </div>
      </Card>

      <Card className="mt-5" title="Organization credit wallet" subtitle="Reauth is required by the API before any adjustment is written.">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Organization ID" type="number" value={organizationId} onChange={(e) => setOrganizationId(e.target.value)} placeholder="123" />
          <Button onClick={loadWallet}>Load wallet</Button>
        </div>

        {loadedWallet && (
          <>
            <div className="mt-5 grid gap-3 sm:grid-cols-3">
              <div><div className="text-xs text-muted">Monthly remaining</div><div className="mt-1 text-xl font-bold text-fg">{num(loadedWallet.monthly_included?.remaining ?? 0)}</div></div>
              <div><div className="text-xs text-muted">Purchased remaining</div><div className="mt-1 text-xl font-bold text-fg">{num(loadedWallet.purchased?.remaining ?? 0)}</div></div>
              <div><div className="text-xs text-muted">Total usable</div><div className="mt-1 text-xl font-bold text-fg">{num(loadedWallet.total_usable ?? 0)}</div></div>
            </div>
            <div className="mt-5 grid gap-3 sm:grid-cols-3">
              <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Action</span><select value={form.action} onChange={(e) => setForm({ ...form, action: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg"><option value="grant">Grant manual credits</option><option value="revoke">Revoke purchased credits</option></select></label>
              <Field label="Amount" type="number" min="1" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
              <Field label="Reason" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} placeholder="Support adjustment" />
            </div>
            <Button className="mt-4" onClick={adjust} disabled={busy || !form.reason.trim()}>{busy ? "Saving…" : "Record adjustment"}</Button>
          </>
        )}
        {message && <p className="mt-4 text-sm text-muted">{message}</p>}
      </Card>
    </div>
  );
}
