"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { num, timeAgo } from "@/lib/format";
import { Activity } from "lucide-react";

function HealthCard({ title, status, detail }: { title: string; status: "Healthy" | "Watch" | "Down"; detail: string }) {
  const tone = status === "Healthy" ? "green" : status === "Watch" ? "amber" : "red";
  return (
    <Card>
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-fg">{title}</h3>
        <Badge tone={tone} dot>{status}</Badge>
      </div>
      <p className="mt-2 text-sm text-muted">{detail}</p>
    </Card>
  );
}

export default function SiteHealthPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const { data, loading } = useApi<any>(siteId ? `/sites/${siteId}/health` : null);

  if (!siteId) return <EmptyState icon={<Activity size={20} />} title="No site connected" hint="Connect a store to monitor its sync health." />;
  if (loading || !data) return <Spinner />;

  const s = data.site || {};
  const connected = s.connection_status === "connected";
  const synced = s.last_sync_at;
  const logs = data.sync_logs || [];

  return (
    <div>
      <PageHeader title="Site Health" subtitle={`Connection, sync, and data integrity for ${site?.name || "your store"}.`} />

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <HealthCard
          title="Connector"
          status={connected ? "Healthy" : "Watch"}
          detail={connected ? `Connected · last sync ${timeAgo(synced)}` : `Status: ${s.connection_status || "pending"}`}
        />
        <HealthCard
          title="WooCommerce sync"
          status={synced ? "Healthy" : "Watch"}
          detail={`${num(data.orders_total)} orders · ${synced ? `synced ${timeAgo(synced)}` : "not synced yet"}`}
        />
        <HealthCard
          title="WordPress version"
          status={s.wp_version || s.wc_version ? "Healthy" : "Watch"}
          detail={`WP ${s.wp_version || "—"} · plugin v${s.plugin_version || "—"} · Woo ${s.wc_version || "—"}`}
        />
      </div>

      <Card className="mt-5" title="Sync log" right={<span className="text-xs text-muted">{site?.domain}</span>}>
        {logs.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted">No sync activity recorded yet.</p>
        ) : (
          <div className="space-y-1">
            {logs.map((l: any, i: number) => (
              <div key={i} className="flex items-center gap-3 border-b border-line py-2.5 text-sm last:border-0">
                <span className="w-16 shrink-0 font-mono text-xs text-muted">
                  {l.finished_at || l.started_at ? new Date(l.finished_at || l.started_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : "—"}
                </span>
                <span className={`h-2 w-2 shrink-0 rounded-full ${l.status === "success" ? "bg-good" : l.status === "error" ? "bg-bad" : "bg-warn"}`} />
                <span className="flex-1 font-mono text-xs text-fg">{l.job}{l.message ? ` — ${l.message}` : ""}</span>
                {l.records != null && <span className="text-xs font-semibold text-muted">+{num(l.records)}</span>}
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
