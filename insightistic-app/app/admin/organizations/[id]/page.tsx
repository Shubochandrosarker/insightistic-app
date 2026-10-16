"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { useApi } from "@/lib/useApi";
import { apiDelete, apiPatch, apiPost } from "@/lib/api";
import { AdminDialog } from "@/components/admin/AdminDialog";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { num, timeAgo } from "@/lib/format";
import { ArrowLeft } from "lucide-react";

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", trialing: "amber", past_due: "amber", suspended: "red", canceled: "slate",
};
const ROLES = ["admin", "analyst", "client_viewer"];
const ROLE_LABEL: Record<string, string> = {
  owner: "Owner", admin: "Admin", analyst: "Analyst", client_viewer: "Client viewer",
};

function EditOrganizationDialog({ open, organization, owner, plan, plans, onClose, onSaved }: { open: boolean; organization: any; owner: any; plan: any; plans: any[]; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState({ name: "", owner_email: "", plan: "", status: "active", trial_ends_at: "", internal_notes: "" });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setForm({
      name: organization?.name || "",
      owner_email: owner?.email || "",
      plan: plan?.slug || "",
      status: organization?.status || "active",
      trial_ends_at: organization?.trial_ends_at ? organization.trial_ends_at.slice(0, 10) : "",
      internal_notes: organization?.internal_notes || "",
    });
    setMessage(null);
  }, [open, organization, owner, plan]);

  async function submit() {
    setBusy(true); setMessage(null);
    try {
      await apiPatch(`/admin/organizations/${organization.id}`, {
        name: form.name.trim(), owner_email: form.owner_email.trim(), plan: form.plan,
        status: form.status, trial_ends_at: form.trial_ends_at || null, internal_notes: form.internal_notes,
      });
      onClose(); onSaved();
    } catch (e: any) { setMessage(e?.message || "That organization could not be saved."); }
    finally { setBusy(false); }
  }

  return <AdminDialog open={open} title={`Edit ${organization?.name || "organization"}`} description="Update ownership, plan, lifecycle status, trial dates, and private platform notes." onClose={onClose}>
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label="Organization name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      <Field label="Owner email" type="email" required value={form.owner_email} onChange={(e) => setForm({ ...form, owner_email: e.target.value })} />
      <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Plan</span><select value={form.plan} onChange={(e) => setForm({ ...form, plan: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="" disabled>Choose plan…</option>{plans.map((p: any) => <option key={p.slug} value={p.slug}>{p.name}</option>)}</select></label>
      <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Status</span><select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="active">Active</option><option value="trialing">Trialing</option><option value="past_due">Past due</option><option value="suspended">Suspended</option><option value="canceled">Canceled</option></select></label>
      <Field label="Trial ends" type="date" value={form.trial_ends_at} onChange={(e) => setForm({ ...form, trial_ends_at: e.target.value })} />
    </div>
    <label className="mt-3 block"><span className="mb-1.5 block text-xs font-semibold text-fg">Internal notes</span><textarea value={form.internal_notes} onChange={(e) => setForm({ ...form, internal_notes: e.target.value })} rows={4} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" /></label>
    {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
    <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>Cancel</Button><Button onClick={submit} disabled={busy || !form.name.trim() || !form.owner_email.trim() || !form.plan}>{busy ? "Saving…" : "Save changes"}</Button></div>
  </AdminDialog>;
}

function MemberAccessRow({ member, sites, organizationId, onChanged }: { member: any; sites: any[]; organizationId: string; onChanged: () => void }) {
  const [role, setRole] = useState(member.role || "analyst");
  const [siteIds, setSiteIds] = useState<number[]>(Array.isArray(member.site_ids) ? member.site_ids.map(Number) : []);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    setRole(member.role || "analyst");
    setSiteIds(Array.isArray(member.site_ids) ? member.site_ids.map(Number) : []);
  }, [member.id, member.role, member.site_ids]);

  function toggleSite(siteId: number) { setSiteIds((current) => current.includes(siteId) ? current.filter((id) => id !== siteId) : [...current, siteId]); }

  async function save() {
    setBusy(true); setMessage(null);
    try { await apiPatch(`/admin/organizations/${organizationId}/members/${member.id}`, { role, site_ids: role === "client_viewer" ? siteIds : [] }); onChanged(); }
    catch (e: any) { setMessage(e?.message || "Could not update this member."); }
    finally { setBusy(false); }
  }

  async function remove() {
    if (!window.confirm(`Remove ${member.name} from this organization?`)) return;
    setBusy(true); setMessage(null);
    try { await apiDelete(`/admin/organizations/${organizationId}/members/${member.id}`); onChanged(); }
    catch (e: any) { setMessage(e?.message || "Could not remove this member."); setBusy(false); }
  }

  return <div className="rounded-xl border border-line bg-card2/45 p-3">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div><p className="text-sm font-semibold text-fg">{member.name}</p><p className="text-xs text-muted">{member.email}{member.status && ` · ${member.status}`}</p></div>
      {member.is_owner ? <Badge tone="violet" dot>Owner (locked)</Badge> : <div className="flex items-center gap-2"><select value={role} onChange={(e) => setRole(e.target.value)} disabled={busy} className="rounded-lg border border-line bg-card px-2 py-1.5 text-xs text-fg outline-none focus:border-brand">{ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}</select><Button className="px-3 py-1.5 text-xs" onClick={save} disabled={busy || (role === "client_viewer" && siteIds.length === 0)}>{busy ? "Saving…" : "Save"}</Button><Button variant="danger" className="px-2 py-1.5 text-xs" onClick={remove} disabled={busy}>Remove</Button></div>}
    </div>
    {member.is_owner ? <p className="mt-2 text-xs text-muted">Owners can access every site in the organization.</p> : role === "client_viewer" ? <div className="mt-3"><p className="mb-1.5 text-xs font-semibold text-fg">Assigned sites</p><div className="flex flex-wrap gap-2">{sites.map((site) => <label key={site.id} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-2.5 py-1 text-xs ${siteIds.includes(site.id) ? "border-brand bg-brand/10 text-brand-700" : "border-line text-muted"}`}><input type="checkbox" className="hidden" checked={siteIds.includes(site.id)} onChange={() => toggleSite(site.id)} />{site.name}</label>)}{sites.length === 0 && <span className="text-xs text-muted">No sites are registered yet.</span>}</div></div> : <p className="mt-2 text-xs text-muted">{ROLE_LABEL[role] || role} can access all organization sites.</p>}
    {message && <p className="mt-2 text-xs text-bad">{message}</p>}
  </div>;
}

function MembersAccess({ organizationId, members, sites, onChanged }: { organizationId: string; members: any[]; sites: any[]; onChanged: () => void }) {
  const usersApi = useApi<any>("/admin/users?per_page=100");
  const [open, setOpen] = useState(false);
  const [userId, setUserId] = useState("");
  const [role, setRole] = useState("analyst");
  const [siteIds, setSiteIds] = useState<number[]>([]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const memberIds = new Set(members.map((member) => member.id));
  const availableUsers = (usersApi.data?.data || []).filter((user: any) => !memberIds.has(user.id));

  function toggleSite(siteId: number) { setSiteIds((current) => current.includes(siteId) ? current.filter((id) => id !== siteId) : [...current, siteId]); }
  async function add() {
    setBusy(true); setMessage(null);
    try { await apiPost(`/admin/organizations/${organizationId}/members`, { user_id: Number(userId), role, site_ids: role === "client_viewer" ? siteIds : [] }); setOpen(false); setUserId(""); setRole("analyst"); setSiteIds([]); onChanged(); }
    catch (e: any) { setMessage(e?.message || "Could not connect this user."); }
    finally { setBusy(false); }
  }

  return <Card title="Users & site access" subtitle="Connect existing accounts to this organization and scope client viewers to exact sites." right={<Button className="px-3 py-1.5 text-xs" onClick={() => { setOpen((value) => !value); setMessage(null); }}>{open ? "Close" : "Connect user"}</Button>}>
    {open && <div className="mb-4 rounded-xl border border-brand/20 bg-brand/5 p-3"><div className="grid gap-3 sm:grid-cols-2"><label className="block sm:col-span-2"><span className="mb-1.5 block text-xs font-semibold text-fg">Existing user</span><select value={userId} onChange={(e) => setUserId(e.target.value)} className="w-full rounded-xl border border-line bg-card px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="">Select a user…</option>{availableUsers.map((user: any) => <option key={user.id} value={user.id}>{user.name} · {user.email}</option>)}</select></label><label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Role</span><select value={role} onChange={(e) => { setRole(e.target.value); if (e.target.value !== "client_viewer") setSiteIds([]); }} className="w-full rounded-xl border border-line bg-card px-3 py-2 text-sm text-fg outline-none focus:border-brand">{ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}</select></label></div>{role === "client_viewer" && <div className="mt-3"><p className="mb-1.5 text-xs font-semibold text-fg">Allowed sites</p><div className="flex flex-wrap gap-2">{sites.map((site) => <label key={site.id} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-2.5 py-1 text-xs ${siteIds.includes(site.id) ? "border-brand bg-brand/10 text-brand-700" : "border-line text-muted"}`}><input type="checkbox" className="hidden" checked={siteIds.includes(site.id)} onChange={() => toggleSite(site.id)} />{site.name}</label>)}{sites.length === 0 && <span className="text-xs text-muted">Add a site before creating a client viewer.</span>}</div></div>}{message && <p className="mt-3 text-sm text-bad">{message}</p>}<div className="mt-3 flex justify-end"><Button onClick={add} disabled={busy || !userId || (role === "client_viewer" && siteIds.length === 0)}>{busy ? "Connecting…" : "Connect user"}</Button></div></div>}
    <div className="space-y-2">{members.map((member: any) => <MemberAccessRow key={member.id} member={member} sites={sites} organizationId={organizationId} onChanged={onChanged} />)}{members.length === 0 && <p className="py-4 text-center text-sm text-muted">No users are connected to this organization.</p>}</div>
    {availableUsers.length === 0 && !open && <p className="mt-3 text-xs text-muted">All current platform users are already connected.</p>}
  </Card>;
}

export default function AdminOrgDetail() {
  const { id } = useParams<{ id: string }>();
  const { data, loading, error, reload } = useApi<any>(`/admin/organizations/${id}`);
  const plansApi = useApi<{ plans: any[] }>("/admin/plans");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [editOpen, setEditOpen] = useState(false);

  async function patch(payload: any) {
    setBusy(true); setErr(null);
    try { await apiPatch(`/admin/organizations/${id}`, payload); reload(); }
    catch (e: any) { setErr(e?.message || "That change could not be saved."); }
    finally { setBusy(false); }
  }

  if (error && !data) return <div><Link href="/admin/organizations" className="mb-3 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><ArrowLeft size={15} /> Organizations</Link><Card><div className="flex flex-col items-center gap-3 py-8 text-center"><Badge tone="red" dot>API error</Badge><p className="max-w-md text-sm text-muted">{error}</p><Button variant="ghost" onClick={reload}>Retry</Button></div></Card></div>;
  if (loading || !data) return <Spinner />;
  const o = data.organization || {};
  const plans = plansApi.data?.plans || [];

  return <div>
    {err && <div className="mb-4 rounded-xl border border-bad/25 bg-bad/10 px-4 py-3 text-sm text-bad">{err}</div>}
    <Link href="/admin/organizations" className="mb-3 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><ArrowLeft size={15} /> Organizations</Link>
    <PageHeader title={o.name} subtitle={`${o.slug} · created ${timeAgo(o.created_at)}`} right={<div className="flex items-center gap-2"><Button variant="ghost" className="px-3 py-1.5 text-xs" onClick={() => setEditOpen(true)}>Edit organization</Button><Badge tone={STATUS_TONE[o.status] || "slate"} dot>{o.status}</Badge></div>} />
    <EditOrganizationDialog open={editOpen} organization={o} owner={data.owner} plan={data.plan} plans={plans} onClose={() => setEditOpen(false)} onSaved={reload} />

    <div className="grid gap-5 lg:grid-cols-3"><div className="space-y-5 lg:col-span-2">
      <Card title="Manage" subtitle="Quick controls are also available here for common support actions."><div className="flex flex-wrap items-end gap-3">
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Name</span><input key={o.name} defaultValue={o.name} onBlur={(e) => { const value = e.target.value.trim(); if (value && value !== o.name) patch({ name: value }); }} disabled={busy} className="w-56 rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" /></label>
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Plan</span><select defaultValue={data.plan?.slug || ""} onChange={(e) => patch({ plan: e.target.value })} disabled={busy} className="rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="" disabled>Choose plan…</option>{plans.map((p: any) => <option key={p.slug} value={p.slug}>{p.name}</option>)}</select></label>
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Owner email</span><input key={data.owner?.email} defaultValue={data.owner?.email || ""} onBlur={(e) => { const value = e.target.value.trim(); if (value && value !== data.owner?.email) patch({ owner_email: value }); }} disabled={busy} className="w-56 rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" /></label>
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Trial ends</span><input type="date" key={o.trial_ends_at} defaultValue={o.trial_ends_at ? o.trial_ends_at.slice(0, 10) : ""} onBlur={(e) => patch({ trial_ends_at: e.target.value || null })} disabled={busy} className="rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" /></label>
        {o.status === "suspended" ? <Button onClick={() => patch({ status: "active" })} disabled={busy}>Reactivate</Button> : <Button variant="danger" onClick={() => patch({ status: "suspended" })} disabled={busy}>Suspend</Button>}
      </div><label className="mt-4 block"><span className="mb-1.5 block text-xs font-semibold text-fg">Internal notes</span><textarea key={o.internal_notes} defaultValue={o.internal_notes || ""} onBlur={(e) => patch({ internal_notes: e.target.value })} disabled={busy} rows={3} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" /></label></Card>

      <Card title={`Sites (${num(data.sites?.length)})`} right={<Link href={`/admin/sites?organization_id=${id}`} className="text-xs font-semibold text-brand-700 hover:underline">Manage sites</Link>}><div className="space-y-1">{(data.sites || []).map((s: any) => <Link key={s.id} href={`/admin/sites/${s.id}`} className="flex items-center justify-between border-b border-line py-2.5 last:border-0"><div><div className="text-sm font-medium text-fg">{s.name}</div><div className="text-[11px] text-muted">{s.domain}</div></div><Badge tone={s.connection_status === "connected" ? "green" : "amber"}>{s.connection_status}</Badge></Link>)}{(!data.sites || !data.sites.length) && <p className="py-4 text-center text-sm text-muted">No sites.</p>}</div></Card>
      <MembersAccess organizationId={id} members={data.members || []} sites={data.sites || []} onChanged={reload} />
      <Card title="Recent sync logs"><div className="space-y-1">{(data.recent_sync_logs || []).map((l: any) => <div key={l.id} className="flex items-center justify-between border-b border-line py-2 text-sm last:border-0"><span className="text-fg">{l.job}</span><span className="flex items-center gap-3"><Badge tone={l.status === "success" ? "green" : l.status === "failed" ? "red" : "slate"}>{l.status}</Badge><span className="text-[11px] text-muted">{timeAgo(l.created_at)}</span></span></div>)}{(!data.recent_sync_logs || !data.recent_sync_logs.length) && <p className="py-4 text-center text-sm text-muted">No sync activity.</p>}</div></Card>
    </div><div className="space-y-5">
      <Card title="Profile"><dl className="space-y-2.5 text-sm"><div className="flex justify-between"><dt className="text-muted">Owner</dt><dd className="text-right font-medium text-fg">{data.owner?.name}</dd></div><div className="flex justify-between"><dt className="text-muted">Owner email</dt><dd className="text-right text-fg">{data.owner?.email}</dd></div><div className="flex justify-between"><dt className="text-muted">Plan</dt><dd className="text-right text-fg">{data.plan?.name || "—"}</dd></div><div className="flex justify-between"><dt className="text-muted">Members</dt><dd className="text-right text-fg">{num(data.members?.length)}</dd></div><div className="flex justify-between"><dt className="text-muted">AI insights</dt><dd className="text-right text-fg">{num(data.ai_insights_count)}</dd></div></dl></Card>
      <Card title="Usage this period"><dl className="space-y-2.5 text-sm"><div className="flex justify-between"><dt className="text-muted">AI insights</dt><dd className="text-fg">{num(data.usage?.ai_insights_used)} / {data.plan?.ai_insight_limit ?? "∞"}</dd></div><div className="flex justify-between"><dt className="text-muted">Reports</dt><dd className="text-fg">{num(data.usage?.reports_generated)} / {data.plan?.report_limit ?? "∞"}</dd></div><div className="flex justify-between"><dt className="text-muted">Sites</dt><dd className="text-fg">{num(data.sites?.length)} / {data.plan?.site_limit ?? "∞"}</dd></div></dl></Card>
      <Card title="Subscription">{data.subscription ? <dl className="space-y-2.5 text-sm"><div className="flex justify-between"><dt className="text-muted">Status</dt><dd className="text-fg">{data.subscription.status}</dd></div><div className="flex justify-between"><dt className="text-muted">Customer</dt><dd className="font-mono text-[11px] text-muted">{data.subscription.stripe_customer_id || "—"}</dd></div></dl> : <p className="text-sm text-muted">No Stripe subscription.</p>}</Card>
    </div></div>
  </div>;
}
