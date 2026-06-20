"use client";
import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { useDashboard } from "@/lib/dashboard";
import { apiPost, apiPatch, apiDelete } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { Spinner } from "@/components/ui/Spinner";

const ROLES = ["admin", "analyst", "client_viewer"];

export default function TeamPage() {
  const { data, loading, reload } = useApi<any>("/organizations/users");
  const { sites } = useDashboard();
  const [f, setF] = useState<any>({ name: "", email: "", role: "analyst", site_ids: [] as number[] });
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function toggleSite(id: number) {
    setF((p: any) => ({ ...p, site_ids: p.site_ids.includes(id) ? p.site_ids.filter((x: number) => x !== id) : [...p.site_ids, id] }));
  }

  async function invite() {
    setBusy(true); setMsg(null);
    try { await apiPost("/organizations/users/invite", f); setF({ name: "", email: "", role: "analyst", site_ids: [] }); setMsg("Invite sent."); reload(); }
    catch (e: any) { setMsg(e.data?.message || e.message); }
    finally { setBusy(false); }
  }
  async function changeRole(id: number, role: string) {
    try { await apiPatch(`/organizations/users/${id}/role`, { role }); reload(); }
    catch (e: any) { setMsg(e.data?.message || e.message); }
  }
  async function remove(id: number) {
    if (!window.confirm("Remove this member?")) return;
    try { await apiDelete(`/organizations/users/${id}`); reload(); }
    catch (e: any) { setMsg(e.data?.message || e.message); }
  }

  if (loading) return <Spinner />;

  return (
    <div className="grid gap-5 lg:grid-cols-3">
      <Card title="Members" className="lg:col-span-2">
        <div className="space-y-2">
          {(data?.members || []).map((m: any) => (
            <div key={m.id} className="flex flex-wrap items-center gap-3 rounded-lg border border-line/60 p-3">
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-medium text-white">{m.name} {m.is_owner && <span className="text-xs text-brand">(owner)</span>}</div>
                <div className="truncate text-xs text-slate-400">{m.email} · {m.status}</div>
              </div>
              {m.is_owner ? (
                <span className="text-xs text-slate-400">owner</span>
              ) : (
                <>
                  <select defaultValue={m.role} onChange={(e) => changeRole(m.id, e.target.value)}
                    className="rounded-lg border border-line bg-ink px-2 py-1 text-xs text-slate-100">
                    {ROLES.map((r) => <option key={r} value={r}>{r}</option>)}
                  </select>
                  <Button variant="danger" onClick={() => remove(m.id)}>Remove</Button>
                </>
              )}
            </div>
          ))}
        </div>
      </Card>

      <Card title="Invite teammate">
        {msg && <p className="mb-3 text-sm text-amber-300">{msg}</p>}
        <div className="space-y-3">
          <Field label="Name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
          <Field label="Email" type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
          <label className="block">
            <span className="mb-1 block text-sm text-slate-300">Role</span>
            <select value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })}
              className="w-full rounded-lg border border-line bg-ink px-3 py-2 text-sm text-slate-100">
              {ROLES.map((r) => <option key={r} value={r}>{r}</option>)}
            </select>
          </label>
          {f.role === "client_viewer" && (
            <div>
              <span className="mb-1 block text-sm text-slate-300">Sites this client can view</span>
              <div className="space-y-1">
                {sites.map((s) => (
                  <label key={s.id} className="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" checked={f.site_ids.includes(s.id)} onChange={() => toggleSite(s.id)} /> {s.name}
                  </label>
                ))}
              </div>
            </div>
          )}
          <Button onClick={invite} disabled={busy}>{busy ? "Inviting…" : "Send invite"}</Button>
        </div>
      </Card>
    </div>
  );
}
