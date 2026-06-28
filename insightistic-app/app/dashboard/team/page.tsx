"use client";
import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { useDashboard } from "@/lib/dashboard";
import { apiPost, apiPatch, apiDelete } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { initials } from "@/lib/format";
import { UserPlus } from "lucide-react";

const ROLES = ["admin", "analyst", "client_viewer"];
const ROLE_TONE: Record<string, "violet" | "green" | "amber" | "slate"> = {
  owner: "violet", admin: "green", analyst: "slate", client_viewer: "amber",
};
const ROLE_LABEL: Record<string, string> = {
  owner: "Owner", admin: "Admin", analyst: "Analyst", client_viewer: "Client viewer",
};
const LEGEND = [
  { role: "owner", desc: "full control + billing" },
  { role: "admin", desc: "manage sites & reports" },
  { role: "analyst", desc: "view & generate, no billing" },
  { role: "client_viewer", desc: "view-only, scoped to assigned sites" },
];

export default function TeamPage() {
  const { data, loading, reload } = useApi<any>("/organizations/users");
  const { sites } = useDashboard();
  const [open, setOpen] = useState(false);
  const [f, setF] = useState<any>({ name: "", email: "", role: "analyst", site_ids: [] as number[] });
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function toggleSite(id: number) {
    setF((p: any) => ({ ...p, site_ids: p.site_ids.includes(id) ? p.site_ids.filter((x: number) => x !== id) : [...p.site_ids, id] }));
  }
  async function invite() {
    setBusy(true); setMsg(null);
    try { await apiPost("/organizations/users/invite", f); setF({ name: "", email: "", role: "analyst", site_ids: [] }); setMsg("Invite sent."); setOpen(false); reload(); }
    catch (e: any) { setMsg(e.message); }
    finally { setBusy(false); }
  }
  async function changeRole(id: number, role: string) {
    try { await apiPatch(`/organizations/users/${id}/role`, { role }); reload(); } catch (e: any) { setMsg(e.message); }
  }
  async function remove(id: number) {
    if (!window.confirm("Remove this member?")) return;
    try { await apiDelete(`/organizations/users/${id}`); reload(); } catch (e: any) { setMsg(e.message); }
  }

  if (loading) return <Spinner />;
  const members = data?.members || [];

  return (
    <div>
      <PageHeader
        title="Team & access"
        subtitle="Invite teammates and scope client viewers to specific sites."
        right={
          <button onClick={() => setOpen((o) => !o)}
            className="inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_20px_-8px_rgba(0,192,75,0.55)] hover:bg-brand2">
            <UserPlus size={16} /> Invite member
          </button>
        }
      />
      {msg && <p className="mb-4 text-sm text-warn">{msg}</p>}

      {open && (
        <Card className="mb-5" title="Invite teammate">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
            <Field label="Email" type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold text-fg">Role</span>
              <select value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })}
                className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand">
                {ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
              </select>
            </label>
          </div>
          {f.role === "client_viewer" && (
            <div className="mt-3">
              <span className="mb-1.5 block text-xs font-semibold text-fg">Sites this client can view</span>
              <div className="flex flex-wrap gap-2">
                {sites.map((s) => (
                  <label key={s.id} className={`flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-1.5 text-sm ${f.site_ids.includes(s.id) ? "border-brand bg-brand/10 text-brand-700" : "border-line text-muted"}`}>
                    <input type="checkbox" className="hidden" checked={f.site_ids.includes(s.id)} onChange={() => toggleSite(s.id)} /> {s.name}
                  </label>
                ))}
              </div>
            </div>
          )}
          <div className="mt-4"><Button onClick={invite} disabled={busy}>{busy ? "Inviting…" : "Send invite"}</Button></div>
        </Card>
      )}

      <Card>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-[11px] uppercase tracking-wide text-muted">
                <th className="border-b border-line pb-2.5 px-2 text-left font-semibold">Member</th>
                <th className="border-b border-line pb-2.5 px-2 text-left font-semibold">Assigned sites</th>
                <th className="border-b border-line pb-2.5 px-2 text-left font-semibold">Role</th>
                <th className="border-b border-line pb-2.5 px-2 text-right font-semibold">Action</th>
              </tr>
            </thead>
            <tbody>
              {members.map((m: any) => (
                <tr key={m.id} className="group">
                  <td className="border-b border-line py-3 px-2 group-last:border-0">
                    <div className="flex items-center gap-3">
                      <span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand/15 text-xs font-bold text-brand-700">{initials(m.name)}</span>
                      <div>
                        <div className="font-medium text-fg">{m.name}</div>
                        <div className="text-[11px] text-muted">{m.email}</div>
                      </div>
                    </div>
                  </td>
                  <td className="border-b border-line py-3 px-2 text-muted group-last:border-0">{m.sites_label || "All sites"}</td>
                  <td className="border-b border-line py-3 px-2 group-last:border-0">
                    {m.is_owner ? (
                      <Badge tone="violet" dot>Owner</Badge>
                    ) : (
                      <select defaultValue={m.role} onChange={(e) => changeRole(m.id, e.target.value)}
                        className="rounded-lg border border-line bg-card2 px-2 py-1 text-xs text-fg outline-none focus:border-brand">
                        {ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
                      </select>
                    )}
                  </td>
                  <td className="border-b border-line py-3 px-2 text-right group-last:border-0">
                    {m.is_owner ? <span className="text-xs text-muted">You</span> : <button onClick={() => remove(m.id)} className="text-sm font-semibold text-bad hover:underline">Remove</button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 border-t border-line pt-4 text-xs text-muted">
          {LEGEND.map((l) => (
            <span key={l.role} className="flex items-center gap-1.5">
              <span className={`h-2 w-2 rounded-full ${l.role === "owner" ? "bg-violet" : l.role === "admin" ? "bg-good" : l.role === "client_viewer" ? "bg-warn" : "bg-muted"}`} />
              <strong className="text-fg">{ROLE_LABEL[l.role]}</strong> — {l.desc}
            </span>
          ))}
        </div>
      </Card>
    </div>
  );
}
