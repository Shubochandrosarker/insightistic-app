"use client";
import Link from "next/link";
import { useState } from "react";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { AdminDialog } from "@/components/admin/AdminDialog";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { useApi } from "@/lib/useApi";
import { apiPatch, apiPost } from "@/lib/api";
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

function RenameDialog({ site, onClose, onSaved }: { site: any; onClose: () => void; onSaved: (patch: any) => void }) {
  const [name, setName] = useState(site.name || "");
  const [status, setStatus] = useState(site.status || "active");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  async function submit() {
    setBusy(true); setMessage(null);
    try { const res = await apiPatch(`/admin/sites/${site.id}`, { name, status }); onSaved(res.site); }
    catch (e: any) { setMessage(e.message || "Couldn't save."); }
    finally { setBusy(false); }
  }
  return (
    <AdminDialog open title="Edit website" description={`Rename or suspend ${site.domain || "this site"}. The domain itself is the connector identity and is not editable here.`} onClose={onClose}>
      <div className="grid gap-3">
        <Field label="Website name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="Main marketing site" />
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Status</span>
          <select value={status} onChange={(e) => setStatus(e.target.value)} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand">
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
          </select>
        </label>
      </div>
      {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
      <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={busy || !name}>{busy ? "Saving…" : "Save"}</Button></div>
    </AdminDialog>
  );
}

export default function AdminSites() {
  const [editing, setEditing] = useState<any | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  async function regenerateKey(site: any, reload: () => void) {
    if (!window.confirm(`Rotate the connector key for "${site.name}"?\n\nThe plugin's current token stops working immediately — the site owner must paste the new token to reconnect.`)) return;
    try {
      const res = await apiPost(`/admin/sites/${site.id}/regenerate-key`);
      reload();
      window.alert(`New connector token (copy it now, shown once):\n\n${res.connector_token}\n\n${res.notice || ""}`);
    } catch (e: any) {
      window.alert(e.message || "Couldn't regenerate the key.");
    }
  }

  return (
    <div>
      {toast && <div className="mb-3 rounded-xl border border-good/25 bg-good/10 px-4 py-2.5 text-sm text-fg">{toast}</div>}
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
        actions={(s: any, reload) => (
          <span className="flex items-center justify-end gap-1.5">
            <button onClick={() => setEditing(s)} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-fg hover:bg-card2">
              Edit
            </button>
            <button onClick={() => regenerateKey(s, reload)} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-fg hover:bg-card2">
              Regenerate key
            </button>
          </span>
        )}
      />
      {editing && (
        <RenameDialog
          site={editing}
          onClose={() => setEditing(null)}
          onSaved={(patch) => { setEditing(null); setToast(`Saved: "${patch?.name}" is now ${patch?.status}.`); setTimeout(() => setToast(null), 6000); setTimeout(() => window.location.reload(), 300); }}
        />
      )}
    </div>
  );
}
