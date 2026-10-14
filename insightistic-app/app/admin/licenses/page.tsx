"use client";

import { useState } from "react";
import { AdminDialog } from "@/components/admin/AdminDialog";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { useApi } from "@/lib/useApi";
import { apiPatch, apiPost } from "@/lib/api";
import { KeyRound, Plus } from "lucide-react";

const TONE: Record<string, "green" | "amber" | "red" | "violet" | "slate"> = {
  active: "green", trialing: "violet", payment_failed: "amber", expired: "amber",
  cancelled: "red", suspended: "red", revoked: "red",
};

function IssueLicense({ onCreated }: { onCreated: () => void }) {
  const orgsApi = useApi<any>("/admin/organizations?per_page=100");
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [rawKey, setRawKey] = useState<string | null>(null);
  const [organizationId, setOrganizationId] = useState("");
  const organizations = orgsApi.data?.data || [];

  function close() { setOpen(false); setMessage(null); setRawKey(null); setOrganizationId(""); }
  async function submit() {
    setBusy(true); setMessage(null);
    try {
      const result = await apiPost("/admin/licenses", { organization_id: Number(organizationId) });
      setRawKey(result.license_key || null);
      onCreated();
    } catch (e: any) { setMessage(e.message || "Couldn't issue the license."); }
    finally { setBusy(false); }
  }

  return <>
    <Button onClick={() => setOpen(true)}><KeyRound size={15} /> Issue license</Button>
    <AdminDialog open={open} title="Issue plugin license" description="Create a one-time Insightistic plugin license for an organization. The raw key is never stored or shown again." onClose={close}>
      {!rawKey ? <>
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Organization</span><select required value={organizationId} onChange={(e) => setOrganizationId(e.target.value)} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="">Select an organization</option>{organizations.map((o: any) => <option key={o.id} value={o.id}>{o.name}</option>)}</select></label>
        {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
        <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={close}>Cancel</Button><Button onClick={submit} disabled={busy || !organizationId}>{busy ? "Issuing…" : "Issue license"}</Button></div>
      </> : <>
        <div className="rounded-xl border border-brand/30 bg-brand/[0.07] p-4"><p className="text-sm font-bold text-fg">Copy this key now</p><p className="mt-1 text-xs text-muted">For security, this is the only time the complete key will appear.</p><code className="mt-3 block break-all rounded-lg border border-line bg-card px-3 py-3 text-sm text-fg">{rawKey}</code></div>
        <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={() => navigator.clipboard?.writeText(rawKey)}>Copy key</Button><Button onClick={close}>Done</Button></div>
      </>}
    </AdminDialog>
  </>;
}

function ManagedLicenseCreate({ onCreated }: { onCreated: () => void }) {
  const orgsApi = useApi<any>("/admin/organizations?per_page=100");
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [form, setForm] = useState({
    organization_id: "", user_id: "", site_id: "", product_name: "", license_type: "plugin",
    provider: "", plan_name: "", license_key: "", status: "active", starts_at: "", expires_at: "", notes: "",
  });
  const selectedOrgId = Number(form.organization_id);
  const usersApi = useApi<any>(`/admin/users?per_page=100${selectedOrgId ? `&organization_id=${selectedOrgId}` : ""}`);
  const sitesApi = useApi<any>("/admin/sites?per_page=100");
  const organizations = orgsApi.data?.data || [];
  const users = usersApi.data?.data || [];
  const sites = sitesApi.data?.data || [];
  const organizationUsers = users;
  const organizationSites = sites.filter((site: any) => !selectedOrgId || site.organization_id === selectedOrgId);

  function update(key: string, value: string) { setForm((current) => ({ ...current, [key]: value })); }
  function close() { setOpen(false); setMessage(null); }
  async function submit() {
    setBusy(true); setMessage(null);
    try {
      await apiPost("/admin/managed-licenses", {
        ...form,
        organization_id: Number(form.organization_id),
        user_id: form.user_id ? Number(form.user_id) : null,
        site_id: form.site_id ? Number(form.site_id) : null,
        starts_at: form.starts_at || null,
        expires_at: form.expires_at || null,
        license_key: form.license_key || null,
      });
      setForm({ organization_id: "", user_id: "", site_id: "", product_name: "", license_type: "plugin", provider: "", plan_name: "", license_key: "", status: "active", starts_at: "", expires_at: "", notes: "" });
      close(); onCreated();
    } catch (e: any) { setMessage(e.message || "Couldn't add the managed license."); }
    finally { setBusy(false); }
  }

  return <>
    <Button variant="ghost" onClick={() => setOpen(true)}><Plus size={15} /> Add managed license</Button>
    <AdminDialog open={open} title="Add managed license" description="Record a premium plugin, theme, SaaS, or service entitlement for a customer. Keys are encrypted at rest and only a masked suffix is shown after saving." onClose={close}>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block sm:col-span-2"><span className="mb-1.5 block text-xs font-semibold text-fg">Organization</span><select required value={form.organization_id} onChange={(e) => { update("organization_id", e.target.value); update("user_id", ""); update("site_id", ""); }} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="">Select an organization</option>{organizations.map((org: any) => <option key={org.id} value={org.id}>{org.name}</option>)}</select></label>
        <Field label="Product or license name" required value={form.product_name} onChange={(e) => update("product_name", e.target.value)} placeholder="Yoast SEO Premium" />
        <Field label="Provider" value={form.provider} onChange={(e) => update("provider", e.target.value)} placeholder="Vendor or marketplace" />
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">License type</span><select value={form.license_type} onChange={(e) => update("license_type", e.target.value)} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="plugin">Plugin</option><option value="theme">Theme</option><option value="saas">SaaS</option><option value="service">Service</option><option value="other">Other</option></select></label>
        <Field label="Plan or tier" value={form.plan_name} onChange={(e) => update("plan_name", e.target.value)} placeholder="Premium / Agency" />
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Assign to user</span><select value={form.user_id} onChange={(e) => update("user_id", e.target.value)} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="">Organization-level</option>{organizationUsers.map((user: any) => <option key={user.id} value={user.id}>{user.name} · {user.email}</option>)}</select></label>
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Assign to website</span><select value={form.site_id} onChange={(e) => update("site_id", e.target.value)} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="">Organization-level</option>{organizationSites.map((site: any) => <option key={site.id} value={site.id}>{site.name} · {site.domain}</option>)}</select></label>
        <Field label="License key" value={form.license_key} onChange={(e) => update("license_key", e.target.value)} placeholder="Stored encrypted; optional" />
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Status</span><select value={form.status} onChange={(e) => update("status", e.target.value)} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="active">Active</option><option value="trialing">Trialing</option><option value="suspended">Suspended</option><option value="expired">Expired</option><option value="canceled">Canceled</option></select></label>
        <Field label="Starts" type="date" value={form.starts_at} onChange={(e) => update("starts_at", e.target.value)} />
        <Field label="Expires" type="date" value={form.expires_at} onChange={(e) => update("expires_at", e.target.value)} />
        <div className="sm:col-span-2"><label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Internal notes</span><textarea value={form.notes} onChange={(e) => update("notes", e.target.value)} rows={3} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" placeholder="Seat count, renewal terms, or support context" /></label></div>
      </div>
      {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
      <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={close}>Cancel</Button><Button onClick={submit} disabled={busy || !form.organization_id || !form.product_name}>{busy ? "Saving…" : "Save managed license"}</Button></div>
    </AdminDialog>
  </>;
}

function ManagedLicenseEdit({ license, onUpdated }: { license: any; onUpdated: () => void }) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [form, setForm] = useState({ status: license.status, plan_name: license.plan_name || "", expires_at: license.expires_at ? license.expires_at.slice(0, 10) : "", notes: license.notes || "" });

  async function submit() {
    setBusy(true); setMessage(null);
    try { await apiPatch(`/admin/managed-licenses/${license.id}`, { ...form, expires_at: form.expires_at || null }); setOpen(false); onUpdated(); }
    catch (e: any) { setMessage(e.message || "Couldn't update the managed license."); }
    finally { setBusy(false); }
  }

  return <>
    <Button variant="ghost" className="px-3 py-1.5 text-xs" onClick={() => setOpen(true)}>Edit</Button>
    <AdminDialog open={open} title={`Edit ${license.product_name}`} description="Update the entitlement state or renewal details. The stored key remains protected." onClose={() => { setOpen(false); setMessage(null); }}>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Status</span><select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="active">Active</option><option value="trialing">Trialing</option><option value="suspended">Suspended</option><option value="expired">Expired</option><option value="canceled">Canceled</option></select></label>
        <Field label="Plan or tier" value={form.plan_name} onChange={(e) => setForm({ ...form, plan_name: e.target.value })} />
        <Field label="Expires" type="date" value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} />
        <div className="sm:col-span-2"><label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Internal notes</span><textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} rows={4} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" /></label></div>
      </div>
      {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
      <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={() => setOpen(false)}>Cancel</Button><Button onClick={submit} disabled={busy}>{busy ? "Saving…" : "Save changes"}</Button></div>
    </AdminDialog>
  </>;
}

function NativeLicenseActions({ license, onUpdated }: { license: any; onUpdated: () => void }) {
  const [limitOpen, setLimitOpen] = useState(false);
  const [limit, setLimit] = useState(String(license.activation_limit ?? 0));
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  async function action(path: string, body: any = {}) {
    setBusy(true); setMessage(null);
    try { await apiPost(`/admin/licenses/${license.id}/${path}`, body); onUpdated(); }
    catch (e: any) { setMessage(e.message || "License action failed."); }
    finally { setBusy(false); }
  }
  async function regenerate() {
    if (!window.confirm("Regenerate this license? The old key stops working immediately.")) return;
    setBusy(true); setMessage(null);
    try {
      const result = await apiPost(`/admin/licenses/${license.id}/regenerate`);
      onUpdated();
      window.alert(`Copy this new license key now (shown once):\n\n${result.license_key}\n\n${result.notice || ""}`);
    } catch (e: any) { setMessage(e.message || "Could not regenerate the license."); }
    finally { setBusy(false); }
  }
  async function saveLimit() {
    setBusy(true); setMessage(null);
    try { await apiPatch(`/admin/licenses/${license.id}/activation-limit`, { limit: Number(limit), reason: reason || null }); setLimitOpen(false); onUpdated(); }
    catch (e: any) { setMessage(e.message || "Could not update the activation limit."); }
    finally { setBusy(false); }
  }

  return <>
    <span className="flex items-center justify-end gap-1.5">
      <button onClick={() => setLimitOpen(true)} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-fg hover:bg-card2">Edit limit</button>
      <button onClick={regenerate} disabled={busy} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-fg hover:bg-card2">Regenerate</button>
      {license.status === "suspended" ? <button onClick={() => action("reactivate")} disabled={busy} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-good">Reactivate</button> : <button onClick={() => action("suspend", { reason: window.prompt("Reason for suspending this license:") || null })} disabled={busy || license.status === "revoked"} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-warn">Suspend</button>}
      <button onClick={() => { if (window.confirm("Disconnect every active site using this license?")) action("disconnect-all", { confirm: true }); }} disabled={busy} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-fg">Disconnect</button>
      <button onClick={() => { if (window.confirm("Revoke this license permanently? This cannot be undone.")) action("revoke", { confirm: true, reason: window.prompt("Reason for revoking this license:") || null }); }} disabled={busy || license.status === "revoked"} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-bad">Revoke</button>
    </span>
    {message && <span className="mt-1 block text-right text-[11px] text-bad">{message}</span>}
    <AdminDialog open={limitOpen} title="Edit activation limit" description={`Set the maximum connected sites for ${license.organization?.name || "this license"}.`} onClose={() => setLimitOpen(false)}>
      <Field label="Activation limit" type="number" min={0} max={10000} value={limit} onChange={(e) => setLimit(e.target.value)} />
      <div className="mt-3"><label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Reason</span><textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" /></label></div>
      <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={() => setLimitOpen(false)}>Cancel</Button><Button onClick={saveLimit} disabled={busy || limit === ""}>{busy ? "Saving…" : "Save limit"}</Button></div>
    </AdminDialog>
  </>;
}

function ManagedLicenses() {
  return <AdminListPage
    title="Managed customer licenses"
    subtitle="Track premium plugins, themes, SaaS plans, and vendor entitlements by organization, user, or website."
    endpoint="/admin/managed-licenses"
    rowKey={(license: any) => license.id}
    searchPlaceholder="Search product, provider, organization, or domain…"
    empty="No managed customer licenses recorded yet."
    headerActions={(reload) => <ManagedLicenseCreate onCreated={reload} />}
    actions={(license: any, reload) => <ManagedLicenseEdit license={license} onUpdated={reload} />}
    filters={[{ key: "status", label: "Any status", options: ["active", "trialing", "suspended", "expired", "canceled"].map((status) => ({ value: status, label: status })) }]}
    columns={[
      { key: "product_name", label: "Product", primary: true, render: (license: any) => <span><span className="block font-medium text-fg">{license.product_name}</span><span className="block text-[11px] text-muted">{license.provider || "Direct"} · {license.license_type}</span></span> },
      { key: "organization", label: "Customer", render: (license: any) => <span><span className="block text-fg">{license.organization?.name || "—"}</span><span className="block text-[11px] text-muted">{license.site?.domain || license.user?.email || "Organization-wide"}</span></span> },
      { key: "plan_name", label: "Plan", render: (license: any) => license.plan_name || "—" },
      { key: "masked_key", label: "Key", render: (license: any) => license.masked_key || "No key" },
      { key: "status", label: "Status", render: (license: any) => <Badge tone={TONE[license.status] || "slate"} dot>{license.status}</Badge> },
      { key: "expires_at", label: "Expires", align: "right", render: (license: any) => license.expires_at ? new Date(license.expires_at).toLocaleDateString() : "ongoing" },
    ]}
  />;
}

export default function AdminLicenses() {
  return <div className="space-y-8">
    <AdminListPage
      title="Insightistic licenses"
      subtitle="Issue and manage plugin entitlements, activation limits, and connected websites."
      endpoint="/admin/licenses"
      rowKey={(license: any) => license.id}
      searchPlaceholder="Search organization, domain, or license suffix…"
      empty="No licenses issued yet."
      headerActions={(reload) => <IssueLicense onCreated={reload} />}
      filters={[{ key: "status", label: "Any status", options: ["active", "trialing", "payment_failed", "suspended", "revoked"].map((status) => ({ value: status, label: status })) }]}
      columns={[
        { key: "organization", label: "Organization", primary: true, render: (license: any) => <span><span className="block font-medium text-fg">{license.organization?.name || "—"}</span><span className="block font-mono text-[11px] text-muted">•••• {license.last4}</span></span> },
        { key: "plan", label: "Plan", render: (license: any) => license.plan?.name || "—" },
        { key: "status", label: "Status", render: (license: any) => <Badge tone={TONE[license.status] || "slate"} dot>{license.status}</Badge> },
        { key: "activation_limit", label: "Sites", align: "right", render: (license: any) => `${license.activations_used ?? 0} / ${license.activation_limit ?? 0}` },
        { key: "last_validated_at", label: "Last check-in", align: "right", render: (license: any) => license.last_validated_at ? new Date(license.last_validated_at).toLocaleDateString() : "never" },
      ]}
      actions={(license: any, reload) => <NativeLicenseActions license={license} onUpdated={reload} />}
    />
    <ManagedLicenses />
  </div>;
}
