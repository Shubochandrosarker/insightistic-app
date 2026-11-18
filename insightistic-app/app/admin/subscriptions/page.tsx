"use client";
import Link from "next/link";
import { useState } from "react";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { AdminDialog } from "@/components/admin/AdminDialog";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";

const TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", trialing: "amber", past_due: "amber", canceled: "slate", incomplete: "slate", unpaid: "red",
};

const fmtDate = (x?: string) => (x ? new Date(x).toLocaleDateString() : "—");

function CreateSubscription({ onCreated }: { onCreated: () => void }) {
  const orgsApi = useApi<any>("/admin/organizations?per_page=100");
  const plansApi = useApi<{ plans: any[] }>("/admin/plans");
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [form, setForm] = useState({ organization_id: "", plan_id: "", status: "active", billing_provider: "manual", current_period_end: "", trial_ends_at: "", internal_override_active: false, internal_override_reason: "" });
  function close() { setOpen(false); setMessage(null); }
  async function submit() {
    setBusy(true); setMessage(null);
    try {
      const result = await apiPost("/admin/subscriptions", { ...form, organization_id: Number(form.organization_id), plan_id: Number(form.plan_id), current_period_end: form.current_period_end || null, trial_ends_at: form.trial_ends_at || null });
      close(); onCreated();
      if (result.license_key) window.alert(`Subscription created. Copy the one-time license key now:\n\n${result.license_key}`);
    }
    catch (e: any) { setMessage(e.message || "Couldn't create the subscription."); }
    finally { setBusy(false); }
  }
  const organizations = orgsApi.data?.data || [];
  return <>
    <Button onClick={() => setOpen(true)}>Add subscription</Button>
    <AdminDialog open={open} title="Add subscription" description="Create a manual entitlement record without exposing or changing provider secrets." onClose={close}>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block sm:col-span-2"><span className="mb-1.5 block text-xs font-semibold text-fg">Organization</span><select required value={form.organization_id} onChange={(e) => setForm({ ...form, organization_id: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="">Select an organization</option>{organizations.map((o: any) => <option key={o.id} value={o.id}>{o.name}</option>)}</select></label>
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Plan</span><select required value={form.plan_id} onChange={(e) => setForm({ ...form, plan_id: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="">Select a plan</option>{(plansApi.data?.plans || []).map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Status</span><select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="active">Active</option><option value="trialing">Trialing</option><option value="past_due">Past due</option><option value="incomplete">Incomplete</option><option value="canceled">Canceled</option></select></label>
        <Field label="Billing provider" value={form.billing_provider} onChange={(e) => setForm({ ...form, billing_provider: e.target.value })} placeholder="manual or stripe" />
        <Field label="Current period ends" type="date" value={form.current_period_end} onChange={(e) => setForm({ ...form, current_period_end: e.target.value })} />
        {form.status === "trialing" && <Field label="Trial ends" type="date" required value={form.trial_ends_at} onChange={(e) => setForm({ ...form, trial_ends_at: e.target.value })} />}
        <label className="sm:col-span-2 flex items-center gap-2 text-sm text-fg"><input type="checkbox" checked={form.internal_override_active} onChange={(e) => setForm({ ...form, internal_override_active: e.target.checked })} /> Apply a manual entitlement override</label>
        {form.internal_override_active && <div className="sm:col-span-2"><Field label="Override reason" required value={form.internal_override_reason} onChange={(e) => setForm({ ...form, internal_override_reason: e.target.value })} placeholder="Partner comp, migration, or support exception" /></div>}
      </div>
      {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
      <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={close}>Cancel</Button><Button onClick={submit} disabled={busy || !form.organization_id || !form.plan_id || (form.status === "trialing" && !form.trial_ends_at) || (form.internal_override_active && !form.internal_override_reason)}>{busy ? "Creating…" : "Create subscription"}</Button></div>
    </AdminDialog>
  </>;
}

export default function AdminSubscriptions() {
  return (
    <AdminListPage
      title="Subscriptions"
      subtitle="Stripe subscription state across the platform. Secret keys are never exposed."
      endpoint="/admin/subscriptions"
      rowKey={(s: any) => s.id}
      searchPlaceholder="Search…"
      empty="No subscriptions yet."
      headerActions={(reload) => <CreateSubscription onCreated={reload} />}
      filters={[
        { key: "status", label: "Any status", options: ["active", "trialing", "past_due", "canceled", "incomplete"].map((v) => ({ value: v, label: v })) },
        { key: "canceled", label: "Cancellation", options: [{ value: "1", label: "Cancels at period end" }] },
      ]}
      columns={[
        {
          key: "organization", label: "Organization", primary: true,
          render: (s: any) => (
            <Link href={`/admin/subscriptions/${s.id}`} className="hover:underline">
              {s.organization?.name || `Org #${s.organization_id}`}
            </Link>
          ),
        },
        { key: "plan", label: "Plan", render: (s: any) => s.plan?.name || "—" },
        { key: "status", label: "Status", render: (s: any) => <Badge tone={TONE[s.status] || "slate"} dot>{s.status}</Badge> },
        { key: "stripe_customer_id", label: "Stripe customer", render: (s: any) => <span className="font-mono text-[11px] text-muted">{s.stripe_customer_id || "—"}</span> },
        { key: "current_period_end", label: "Renews", align: "right", render: (s: any) => fmtDate(s.current_period_end) },
        { key: "cancel_at_period_end", label: "Cancels", align: "right", render: (s: any) => s.cancel_at_period_end ? <Badge tone="red">yes</Badge> : "—" },
      ]}
    />
  );
}
