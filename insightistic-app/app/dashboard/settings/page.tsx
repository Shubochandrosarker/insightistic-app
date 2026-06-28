"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { timeAgo } from "@/lib/format";

function Toggle({ label, defaultOn = false }: { label: string; defaultOn?: boolean }) {
  const [on, setOn] = useState(defaultOn);
  return (
    <div className="flex items-center justify-between py-2.5">
      <span className="text-sm text-fg">{label}</span>
      <button
        onClick={() => setOn((v) => !v)}
        className={`relative h-6 w-11 rounded-full transition ${on ? "bg-brand" : "bg-black/15 dark:bg-white/15"}`}
        aria-pressed={on}
      >
        <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${on ? "left-[22px]" : "left-0.5"}`} />
      </button>
    </div>
  );
}

export default function SettingsPage() {
  const { sites, siteId, reloadSites, setSiteId } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const [token, setToken] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [newSite, setNewSite] = useState({ name: "", domain: "" });
  const [busy, setBusy] = useState(false);
  const [adding, setAdding] = useState(false);

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
      setNewSite({ name: "", domain: "" }); setAdding(false);
      await reloadSites();
      if (r.site?.id) setSiteId(r.site.id);
    } catch (e: any) { setMsg(e.message); }
    finally { setBusy(false); }
  }

  return (
    <div>
      <PageHeader
        title="Settings"
        subtitle={`Site connection and sync for ${site?.name || "your workspace"}.`}
        right={<Button variant="ghost" onClick={() => setAdding((a) => !a)}>Add a site</Button>}
      />

      {adding && (
        <Card className="mb-5" title="Add a site">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Site name" value={newSite.name} onChange={(e) => setNewSite({ ...newSite, name: e.target.value })} />
            <Field label="Domain" value={newSite.domain} onChange={(e) => setNewSite({ ...newSite, domain: e.target.value })} placeholder="store.example.com" />
          </div>
          <div className="mt-4"><Button onClick={addSite} disabled={busy || !newSite.name}>{busy ? "Creating…" : "Create site"}</Button></div>
        </Card>
      )}

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Site connection">
          {site ? (
            <div className="space-y-4">
              <Field label="Site name" value={site.name} readOnly />
              <Field label="Domain" value={site.domain || ""} readOnly />
              <div>
                <span className="mb-1.5 block text-xs font-semibold text-fg">Connector status</span>
                <div className="flex items-center gap-3">
                  <Badge tone={site.connection_status === "connected" ? "green" : "amber"} dot>{site.connection_status}</Badge>
                  <span className="text-xs text-muted">{site.last_sync_at ? `last sync ${timeAgo(site.last_sync_at)}` : "never synced"}</span>
                </div>
              </div>
              <div className="pt-1">
                <Button variant="ghost" onClick={regenerate}>Regenerate connector token</Button>
              </div>
            </div>
          ) : (
            <p className="text-sm text-muted">No site selected. Use “Add a site” to connect one.</p>
          )}
        </Card>

        <Card title="Sync settings" subtitle="Choose what the connector keeps in sync.">
          <div className="divide-y divide-line">
            <Toggle label="WooCommerce orders" defaultOn />
            <Toggle label="Products" defaultOn />
            <Toggle label="Customers" defaultOn />
            <Toggle label="Site health" defaultOn />
            <Toggle label="Form leads" />
            <Toggle label="Email automation" />
          </div>
        </Card>
      </div>

      {(token || msg) && (
        <Card className="mt-5" title="Connector token">
          {msg && <p className="mb-2 text-sm text-good">{msg}</p>}
          {token && <code className="block break-all rounded-xl border border-line bg-card2 p-3 text-xs text-fg">{token}</code>}
          <p className="mt-2 text-xs text-muted">Paste this into the Insightistic Connector plugin → Connection tab. Shown once.</p>
        </Card>
      )}
    </div>
  );
}
