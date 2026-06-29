"use client";
import { useParams } from "next/navigation";
import Link from "next/link";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { num, timeAgo } from "@/lib/format";
import { ArrowLeft } from "lucide-react";

export default function AdminSiteDetail() {
  const { id } = useParams<{ id: string }>();
  const { data, loading } = useApi<any>(`/admin/sites/${id}`);

  if (loading || !data) return <Spinner />;
  const s = data.site || {};
  const c = data.counts || {};

  return (
    <div>
      <Link href="/admin/sites" className="mb-3 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg">
        <ArrowLeft size={15} /> Sites
      </Link>
      <PageHeader
        title={s.name}
        subtitle={`${s.domain || "—"} · ${s.organization?.name || ""}`}
        right={<Badge tone={s.connection_status === "connected" ? "green" : "amber"} dot>{s.connection_status}</Badge>}
      />

      <div className="mb-5 grid grid-cols-2 gap-3.5 md:grid-cols-4">
        {[
          { label: "Orders", value: num(c.orders) },
          { label: "Products", value: num(c.products) },
          { label: "Customers", value: num(c.customers) },
          { label: "Last sync", value: s.last_sync_at ? timeAgo(s.last_sync_at) : "never" },
        ].map((k) => (
          <div key={k.label} className="rounded-2xl border border-line bg-card p-4 shadow-card">
            <div className="text-xs font-medium text-muted">{k.label}</div>
            <div className="mt-1.5 text-xl font-bold text-fg">{k.value}</div>
          </div>
        ))}
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Connection">
          <dl className="space-y-2.5 text-sm">
            <div className="flex justify-between"><dt className="text-muted">Platform</dt><dd className="text-fg">{s.platform || "—"}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">WordPress</dt><dd className="text-fg">{s.wp_version || "—"}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">WooCommerce</dt><dd className="text-fg">{s.wc_version || "—"}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">Plugin</dt><dd className="text-fg">{s.plugin_version ? `v${s.plugin_version}` : "—"}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">Timezone</dt><dd className="text-fg">{s.timezone || "—"}</dd></div>
          </dl>
        </Card>

        <Card title="Recent errors">
          {(data.recent_errors || []).length === 0 ? (
            <p className="py-6 text-center text-sm text-muted">No recent errors 🎉</p>
          ) : (
            <div className="space-y-2">
              {data.recent_errors.map((e: any) => (
                <div key={e.id} className="rounded-xl border border-line bg-card2 p-3 text-sm">
                  <div className="flex items-center justify-between"><span className="font-semibold text-fg">{e.job}</span><span className="text-[11px] text-muted">{timeAgo(e.created_at)}</span></div>
                  <p className="mt-1 text-[12px] text-bad">{e.message}</p>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      <Card className="mt-5" title="Sync log">
        <div className="space-y-1">
          {(data.recent_sync_logs || []).map((l: any) => (
            <div key={l.id} className="flex items-center justify-between border-b border-line py-2.5 text-sm last:border-0">
              <span className="text-fg">{l.job}{l.message ? ` — ${l.message}` : ""}</span>
              <span className="flex items-center gap-3">
                <Badge tone={l.status === "success" ? "green" : l.status === "failed" ? "red" : "slate"}>{l.status}</Badge>
                <span className="text-[11px] text-muted">{l.records != null ? `+${num(l.records)}` : ""}</span>
              </span>
            </div>
          ))}
          {(!data.recent_sync_logs || !data.recent_sync_logs.length) && <p className="py-4 text-center text-sm text-muted">No sync activity.</p>}
        </div>
      </Card>
    </div>
  );
}
