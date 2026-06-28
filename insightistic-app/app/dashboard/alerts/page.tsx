"use client";
import { useMemo, useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";
import { AlertTriangle, Bell } from "lucide-react";

type Sev = "high" | "medium" | "low";
interface Alert { id: string; title: string; note: string; severity: Sev; }

const SEV_TONE: Record<Sev, "red" | "amber" | "slate"> = { high: "red", medium: "amber", low: "slate" };

export default function AlertsPage() {
  const { siteId, period, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);

  const ov = useApi<any>(siteId ? `/sites/${siteId}/analytics/overview?period=${period}` : null, [period]);
  const prod = useApi<any>(siteId ? `/sites/${siteId}/analytics/products?period=${period}` : null, [period]);
  const ins = useApi<any>(siteId ? `/sites/${siteId}/insights` : null);
  const [resolved, setResolved] = useState<Record<string, boolean>>({});

  const alerts = useMemo<Alert[]>(() => {
    const list: Alert[] = [];
    const m = ov.data?.metrics || {};

    (prod.data?.low_stock || []).forEach((p: any, i: number) =>
      list.push({
        id: `stock-${i}`,
        title: `${p.name || "Product"} ${p.stock_status === "outofstock" ? "is out of stock" : "is low on stock"}`,
        note: `${num(p.stock_quantity)} units · SKU ${p.sku || "—"}`,
        severity: p.stock_status === "outofstock" ? "high" : "medium",
      }));

    if ((m.failed_orders || 0) > 0)
      list.push({ id: "failed", title: "Failed orders detected", note: `${num(m.failed_orders)} orders failed — check the payment gateway`, severity: "high" });
    if ((m.refunds || 0) > 0)
      list.push({ id: "refunds", title: "Refunds in this period", note: `${num(m.refunds)} refunds — review the affected products`, severity: "medium" });

    (ins.data?.insights || [])
      .filter((x: any) => x.severity === "high" || x.severity === "medium")
      .slice(0, 4)
      .forEach((x: any) => list.push({ id: `insight-${x.id}`, title: x.title, note: x.summary, severity: x.severity }));

    return list;
  }, [ov.data, prod.data, ins.data]);

  if (!siteId) return <EmptyState icon={<Bell size={20} />} title="No site connected" hint="Connect a store to see alerts." />;
  if (ov.loading || prod.loading) return <Spinner />;

  const visible = alerts.filter((a) => !resolved[a.id]);

  return (
    <div>
      <PageHeader title="Alerts" subtitle={`Things that need your attention across ${site?.name || "your store"}.`} />
      {visible.length === 0 ? (
        <EmptyState icon={<AlertTriangle size={20} />} title="All clear" hint="No alerts right now. We’ll flag stock, refund and sync issues here as they happen." />
      ) : (
        <div className="space-y-3">
          {visible.map((a) => (
            <Card key={a.id}>
              <div className="flex items-center justify-between gap-4">
                <div className="flex items-start gap-3">
                  <span className={`mt-0.5 flex h-9 w-9 items-center justify-center rounded-xl ${a.severity === "high" ? "bg-bad/12 text-bad" : a.severity === "medium" ? "bg-warn/15 text-warn" : "bg-black/5 text-muted"}`}>
                    <AlertTriangle size={16} />
                  </span>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-fg">{a.title}</span>
                      <Badge tone={SEV_TONE[a.severity]}>{a.severity}</Badge>
                    </div>
                    <div className="mt-0.5 text-sm text-muted">{a.note}</div>
                  </div>
                </div>
                <Button variant="ghost" onClick={() => setResolved((r) => ({ ...r, [a.id]: true }))}>Resolve</Button>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
