"use client";

import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiGet, apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";

export default function AdminBilling() {
  const { data: campaignData, loading: campaignLoading, reload: reloadCampaign } = useApi<any>("/admin/billing/ltd-campaign");
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

  if (campaignLoading) return <Spinner />;

  const campaign = campaignData?.campaign;
  const loadedWallet = wallet?.wallet;

  return (
    <div>
      <PageHeader title="Billing operations" subtitle="Review Founding Angel inventory and perform audited wallet adjustments." />

      <Card title="Founding Angel campaign" subtitle="The backend owns the 100-license cap; this view is read-only.">
        <div className="flex flex-wrap items-center gap-3">
          <Badge tone={campaign?.sold_out ? "slate" : "green"} dot>{campaign?.sold_out ? "Sold out" : "Active"}</Badge>
          <span className="text-sm text-muted">
            {num(campaign?.successful_sales ?? 0)} successful · {num(campaign?.reserved_sales ?? 0)} reserved · {num(campaign?.remaining_sales ?? 0)} remaining of {num(campaign?.maximum_sales ?? 100)}
          </span>
        </div>
        <Button variant="ghost" className="mt-4" onClick={reloadCampaign}>Refresh campaign</Button>
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
