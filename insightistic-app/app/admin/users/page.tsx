"use client";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { AdminDialog } from "@/components/admin/AdminDialog";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { apiPatch, apiPost, getToken, setImpersonation } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { num, timeAgo, initials } from "@/lib/format";

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", disabled: "red", suspended: "amber", invited: "slate",
};

function CreateUser({ onCreated }: { onCreated: () => void }) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [form, setForm] = useState({ name: "", email: "", password: "", status: "active" });
  function close() { setOpen(false); setMessage(null); }
  async function submit() {
    setBusy(true); setMessage(null);
    try { await apiPost("/admin/users", form); close(); onCreated(); }
    catch (e: any) { setMessage(e.message || "Couldn't create the user."); }
    finally { setBusy(false); }
  }
  return <>
    <Button onClick={() => setOpen(true)}>Add user</Button>
    <AdminDialog open={open} title="Add user" description="Create an account that can own organizations or be invited to a team." onClose={close}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Full name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Jane Smith" />
        <Field label="Email" type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="jane@example.com" />
        <Field label="Temporary password" type="password" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="At least 12 characters" hint="Give the user a secure temporary password and ask them to change it." />
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Initial status</span><select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="active">Active</option><option value="disabled">Disabled</option><option value="suspended">Suspended</option></select></label>
      </div>
      {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
      <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={close}>Cancel</Button><Button onClick={submit} disabled={busy || !form.name || !form.email || form.password.length < 12}>{busy ? "Creating…" : "Create user"}</Button></div>
    </AdminDialog>
  </>;
}

function EditUser({ user, onUpdated }: { user: any; onUpdated: () => void }) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [form, setForm] = useState({ name: user.name || "", email: user.email || "", password: "", status: user.status || "active" });
  function close() { setOpen(false); setMessage(null); }
  async function submit() {
    setBusy(true); setMessage(null);
    try {
      const payload: any = { name: form.name, email: form.email, status: form.status };
      if (form.password) payload.password = form.password;
      await apiPatch(`/admin/users/${user.id}`, payload);
      close(); onUpdated();
    } catch (e: any) { setMessage(e.message || "Couldn't update the user."); }
    finally { setBusy(false); }
  }
  return <>
    <Button variant="ghost" className="px-3 py-1.5 text-xs" onClick={() => setOpen(true)}>Edit</Button>
    <AdminDialog open={open} title={`Edit ${user.name}`} description="Update the account profile, status, or issue a new temporary password. Privilege changes stay in the separate protected actions." onClose={close}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Full name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <Field label="Email" type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
        <Field label="New password (optional)" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="At least 12 characters" />
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-fg">Status</span><select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"><option value="active">Active</option><option value="disabled">Disabled</option><option value="suspended">Suspended</option></select></label>
      </div>
      {message && <p className="mt-3 rounded-lg border border-bad/20 bg-bad/10 px-3 py-2 text-sm text-bad">{message}</p>}
      <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={close}>Cancel</Button><Button onClick={submit} disabled={busy || !form.name || !form.email || (!!form.password && form.password.length < 12)}>{busy ? "Saving…" : "Save changes"}</Button></div>
    </AdminDialog>
  </>;
}

export default function AdminUsers() {
  const router = useRouter();
  const { applyToken } = useAuth();

  async function toggle(u: any, reload: () => void) {
    const next = u.status === "active" ? "disabled" : "active";
    try { await apiPatch(`/admin/users/${u.id}`, { status: next }); reload(); }
    catch (e: any) { window.alert(e.message || "Failed."); }
  }

  async function togglePromotion(u: any, reload: () => void) {
    try { await apiPost(`/admin/users/${u.id}/${u.is_super_admin ? "demote" : "promote"}`); reload(); }
    catch (e: any) { window.alert(e.message || "Failed."); }
  }

  async function impersonate(u: any) {
    const reason = window.prompt(`Why are you viewing as ${u.name}? (required, visible in the audit log)`);
    if (!reason) return;
    const adminToken = getToken();
    if (!adminToken) return;
    try {
      const res = await apiPost("/admin/impersonate", { user_id: u.id, reason });
      setImpersonation(adminToken, res.impersonation);
      await applyToken(res.token);
      router.push("/dashboard");
    } catch (e: any) {
      window.alert(e.message || "Couldn't start impersonation.");
    }
  }

  return (
    <AdminListPage
      title="Users"
      subtitle="Every account across all organizations."
      endpoint="/admin/users"
      rowKey={(u: any) => u.id}
      searchPlaceholder="Search name or email…"
      empty="No users found."
      headerActions={(reload) => <CreateUser onCreated={reload} />}
      filters={[
        { key: "super_admin", label: "All users", options: [{ value: "1", label: "Super admins" }, { value: "0", label: "Standard" }] },
        { key: "status", label: "Any status", options: ["active", "disabled", "suspended"].map((s) => ({ value: s, label: s })) },
      ]}
      columns={[
        {
          key: "name", label: "User", primary: true,
          render: (u: any) => (
            <span className="flex items-center gap-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand/15 text-xs font-bold text-brand-700">{initials(u.name)}</span>
              <span>
                <span className="flex items-center gap-2 font-medium text-fg">{u.name}{u.is_super_admin && <Badge tone="violet">admin</Badge>}</span>
                <span className="block text-[11px] text-muted">{u.email}</span>
              </span>
            </span>
          ),
        },
        { key: "organizations_count", label: "Orgs", align: "right", render: (u: any) => num(u.organizations_count) },
        { key: "status", label: "Status", render: (u: any) => <Badge tone={STATUS_TONE[u.status] || "slate"} dot>{u.status || "active"}</Badge> },
        { key: "last_login_at", label: "Last login", align: "right", render: (u: any) => <span className="text-muted">{u.last_login_at ? timeAgo(u.last_login_at) : "never"}</span> },
      ]}
      actions={(u: any, reload) => (
        <span className="flex items-center justify-end gap-1.5">
          <EditUser user={u} onUpdated={reload} />
          {!u.is_super_admin && (
            <button onClick={() => impersonate(u)} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-fg hover:bg-card2">
              Impersonate
            </button>
          )}
          <button onClick={() => togglePromotion(u, reload)} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-fg hover:bg-card2">
            {u.is_super_admin ? "Demote" : "Promote"}
          </button>
          <button onClick={() => toggle(u, reload)} className={`rounded-lg border border-line px-2.5 py-1 text-xs font-semibold ${u.status === "active" ? "text-bad" : "text-good"}`}>
            {u.status === "active" ? "Disable" : "Enable"}
          </button>
        </span>
      )}
    />
  );
}
