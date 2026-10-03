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
import { num } from "@/lib/format";

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", trialing: "amber", past_due: "amber", suspended: "red", canceled: "slate",
};

function CreateOrganization({ onCreated }: { onCreated: () => void }) {
  const plansApi = useApi<{ plans: any[] }>("/admin/plans");
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [form, setForm] = useState({ name: "", owner_email: "", plan: "free", status: "active", trial_ends_at: "", internal_notes: "" });

  function close() { setOpen(false); setMessage(null); }
  async function submit() {
    setBusy(true); setMessage(null);
    try {
      const result = await apiPost("/admin/organizations", { ...form, trial_ends_at: form.trial_ends_at || null });
      close(); onCreated();
      if (result.license_key) window.alert(`Organization created. Copy the one-time license key now:\n\n${result.license_key}`);
    } catch (e: any) { setMessage(e.message || "Couldn't create the organization."); }
    finally { setBusy(false); }
  }

  return <>
    <Button onClick={() => setOpen(true)}>Add organization</Button>
    <AdminDialog open={open} title="Add organization" description="Create a tenant, assign its owner and set its initial entitlement." onClose={close}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Organization name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Acme Digital" />
        <Field label="Owner email" type="email" required value={form.owner_email} onChange={(e) => setForm({ ...form, owner_email: e.target.value })} placeholder="owner@example.com" hint="The user must already exist. Create them from Users first." />
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Plan</span><select value={form.plan} onChange={(e) => setForm({ ...form, plan: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="">No plan</option>{(plansApi.data?.plans || []).map((p: any) => <option key={p.slug} value={p.slug}>{p.name}</option>)}</select></label>
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Status</span><select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="active">Active</option><option value="trialing">Trialing</option><option value="past_due">Past due</option><option value="suspended">Suspended</option></select></label>
        <Field label="Trial ends" type="date" value={form.trial_ends_at} onChange={(e) => setForm({ ...form, trial_ends_at: e.target.value })} />
        <div className="sm:col-span-2"><label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Internal notes</span><textarea value={form.internal_notes} onChange={(e) => setForm({ ...form, internal_notes: e.target.value })} rows={3} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" placeholder="Manual deal, onboarding context, or support notes" /></label></div>
      </div>
      {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
      <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={close}>Cancel</Button><Button onClick={submit} disabled={busy || !form.name || !form.owner_email || (form.status === "trialing" && !form.trial_ends_at)}>{busy ? "Creating…" : "Create organization"}</Button></div>
    </AdminDialog>
  </>;
}

export default function AdminOrganizations() {
  return (
    <AdminListPage
      title="Organizations"
      subtitle="Every tenant on the platform."
      endpoint="/admin/organizations"
      rowKey={(o: any) => o.id}
      searchPlaceholder="Search name, slug, or owner email…"
      empty="No organizations found."
      headerActions={(reload) => <CreateOrganization onCreated={reload} />}
      filters={[
        { key: "plan", label: "All plans", options: ["starter", "growth", "business", "agency", "agency_pro"].map((p) => ({ value: p, label: p })) },
        { key: "status", label: "All statuses", options: ["trialing", "active", "past_due", "suspended", "canceled"].map((s) => ({ value: s, label: s })) },
      ]}
      columns={[
        {
          key: "name", label: "Organization", primary: true,
          render: (o: any) => (
            <Link href={`/admin/organizations/${o.id}`} className="hover:underline">
              <span className="block font-medium text-fg">{o.name}</span>
              <span className="block text-[11px] text-muted">{o.owner?.email || o.slug}</span>
            </Link>
          ),
        },
        { key: "plan", label: "Plan", render: (o: any) => o.plan?.name || "—" },
        { key: "status", label: "Status", render: (o: any) => <Badge tone={STATUS_TONE[o.status] || "slate"} dot>{o.status}</Badge> },
        { key: "sites_count", label: "Sites", align: "right", render: (o: any) => num(o.sites_count) },
        { key: "users_count", label: "Team", align: "right", render: (o: any) => num(o.users_count) },
      ]}
    />
  );
}
