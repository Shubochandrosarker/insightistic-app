"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";

export default function SettingsPage() {
  const { sites, siteId, reloadSites, setSiteId } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const [token, setToken] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [newSite, setNewSite] = useState({ name: "", domain: "" });
  const [busy, setBusy] = useState(false);

  async function regenerate() {
    if (!siteId) return;
    if (!window.confirm("Regenerate the connector token? The old one stops working.")) return;
    try { const r = await apiPost(`/sites/${siteId}/regenerate-api-key`); setToken(r.connector_token); setMsg("New token generated — update your plugin."); }
    catch (e: any) { setMsg(e.message); }
  }

  async function addSite() {
    setBusy(true); setMsg(null);
    try {
      const r = await apiPost("/sites", newSite);
      setToken(r.connector_token); setMsg("Site created — copy this token into the plugin (shown once).");
      setNewSite({ name: "", domain: "" });
      await reloadSites();
      if (r.site?.id) setSiteId(r.site.id);
    } catch (e: any) { setMsg(e.data?.message || e.message); }
    finally { setBusy(false); }
  }

  return (
    <div className="max-w-xl space-y-5">
      <Card title="Current site">
        {site ? (
          <div className="space-y-1 text-sm text-slate-300">
            <div><span className="text-slate-400">Name:</span> {site.name}</div>
            <div><span className="text-slate-400">Domain:</span> {site.domain || "—"}</div>
            <div><span className="text-slate-400">Status:</span> {site.connection_status}</div>
            <div><span className="text-slate-400">Last sync:</span> {site.last_sync_at ? new Date(site.last_sync_at).toLocaleString() : "never"}</div>
            <div><span className="text-slate-400">Plugin:</span> {site.plugin_version || "—"} · WC {site.wc_version || "—"}</div>
            <div className="pt-3"><Button variant="ghost" onClick={regenerate}>Regenerate connector token</Button></div>
          </div>
        ) : <p className="text-sm text-slate-400">No site selected.</p>}
      </Card>

      <Card title="Add a site">
        <div className="space-y-3">
          <Field label="Site name" value={newSite.name} onChange={(e) => setNewSite({ ...newSite, name: e.target.value })} />
          <Field label="Domain" value={newSite.domain} onChange={(e) => setNewSite({ ...newSite, domain: e.target.value })} placeholder="store.example.com" />
          <Button onClick={addSite} disabled={busy}>{busy ? "Creating…" : "Create site"}</Button>
        </div>
      </Card>

      {(token || msg) && (
        <Card title="Connector token">
          {msg && <p className="mb-2 text-sm text-emerald-400">{msg}</p>}
          {token && <code className="block break-all rounded-lg border border-line bg-ink p-3 text-xs text-slate-200">{token}</code>}
          <p className="mt-2 text-xs text-slate-500">Paste this into the Insightistic Connector plugin → Connection tab. Shown once.</p>
        </Card>
      )}
    </div>
  );
}
