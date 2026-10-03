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
import { timeAgo } from "@/lib/format";

const TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  connected: "green", not_installed: "slate", pending: "amber", disconnected: "red", error: "red",
};

function CreateSite({ onCreated }: { onCreated: () => void }) {
  const orgsApi = useApi<any>("/admin/organizations?per_page=100");
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [form, setForm] = useState({ organization_id: "", name: "", domain: "", platform: "generic", country: "", language: "" });
  function close() { setOpen(false); setMessage(null); }
  async function submit() {
    setBusy(true); setMessage(null);
    try { await apiPost("/admin/sites", { ...form, organization_id: Number(form.organization_id) }); close(); onCreated(); }
    catch (e: any) { setMessage(e.message || "Couldn't add the website."); }
    finally { setBusy(false); }
  }
  const organizations = orgsApi.data?.data || [];
  return <>
    <Button onClick={() => setOpen(true)}>Add website</Button>
    <AdminDialog open={open} title="Add website" description="Register a site under an organization. The connector stays disconnected until the owner connects it." onClose={close}>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block sm:col-span-2"><span className="mb-1.5 block text-xs font-semibold text-fg">Organization</span><select required value={form.organization_id} onChange={(e) => setForm({ ...form, organization_id: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="">Select an organization</option>{organizations.map((o: any) => <option key={o.id} value={o.id}>{o.name} · {o.owner?.email || o.slug}</option>)}</select></label>
        <Field label="Website name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Main marketing site" />
        <Field label="Domain or URL" required value={form.domain} onChange={(e) => setForm({ ...form, domain: e.target.value })} placeholder="https://example.com" />
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Platform</span><select value={form.platform} onChange={(e) => setForm({ ...form, platform: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="generic">Any website</option><option value="wordpress">WordPress</option><option value="woocommerce">WooCommerce</option><option value="shopify">Shopify</option><option value="laravel">Laravel</option></select></label>
        <Field label="Country" value={form.country} onChange={(e) => setForm({ ...form, country: e.target.value.toUpperCase().slice(0, 2) })} placeholder="US" maxLength={2} />
        <Field label="Language" value={form.language} onChange={(e) => setForm({ ...form, language: e.target.value })} placeholder="en-US" />
      </div>
      {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
      <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={close}>Cancel</Button><Button onClick={submit} disabled={busy || !form.organization_id || !form.domain}>{busy ? "Adding…" : "Add website"}</Button></div>
    </AdminDialog>
  </>;
}

export default function AdminSites() {
  return (
    <AdminListPage
      title="Sites"
      subtitle="Every connected WordPress / WooCommerce store."
      endpoint="/admin/sites"
      rowKey={(s: any) => s.id}
      searchPlaceholder="Search site name or domain…"
      empty="No sites found."
      headerActions={(reload) => <CreateSite onCreated={reload} />}
      filters={[
        { key: "connection_status", label: "Any status", options: ["connected", "not_installed", "pending", "disconnected"].map((v) => ({ value: v, label: v })) },
      ]}
      columns={[
        {
          key: "name", label: "Site", primary: true,
          render: (s: any) => (
            <Link href={`/admin/sites/${s.id}`} className="hover:underline">
              <span className="block font-medium text-fg">{s.name}</span>
              <span className="block text-[11px] text-muted">{s.domain || "—"}</span>
            </Link>
          ),
        },
        { key: "organization", label: "Organization", render: (s: any) => s.organization?.name || "—" },
        { key: "connection_status", label: "Status", render: (s: any) => <Badge tone={TONE[s.connection_status] || "slate"} dot>{s.connection_status}</Badge> },
        { key: "plugin_version", label: "Plugin", align: "right", render: (s: any) => s.plugin_version ? `v${s.plugin_version}` : "—" },
        { key: "last_sync_at", label: "Last sync", align: "right", render: (s: any) => <span className="text-muted">{s.last_sync_at ? timeAgo(s.last_sync_at) : "never"}</span> },
      ]}
    />
  );
}
