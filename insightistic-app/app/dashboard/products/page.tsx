"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { money, num } from "@/lib/format";
import { Package } from "lucide-react";

export default function ProductsPage() {
  const { siteId, period, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const cur = site?.currency as string | undefined;
  const { data, loading } = useApi<any>(siteId ? `/sites/${siteId}/analytics/products?period=${period}` : null, [period]);

  if (!siteId) return <EmptyState icon={<Package size={20} />} title="No site connected" hint="Connect a store to see product performance." />;
  if (loading || !data) return <Spinner />;

  const top = data.top_products || [];
  const low = data.low_stock || [];

  return (
    <div>
      <PageHeader title="Products" subtitle={`Winners and stock risk for ${site?.name || "your store"}.`} />

      <div className="grid gap-5 lg:grid-cols-3">
        <Card className="lg:col-span-2" title="Top performing products">
          <ResponsiveTable
            rows={top}
            rowKey={(p: any, i) => p.product_id ?? i}
            empty="No product sales in range."
            columns={[
              {
                key: "name", label: "Product", primary: true,
                render: (p: any) => (
                  <span>
                    <span className="block font-medium text-fg">{p.name || "—"}</span>
                    <span className="block text-[11px] text-muted">{p.sku || `#${p.product_id}`}</span>
                  </span>
                ),
              },
              { key: "units", label: "Sold", align: "right", render: (p: any) => num(p.units) },
              { key: "revenue", label: "Revenue", align: "right", render: (p: any) => <span className="font-semibold text-fg">{money(p.revenue, cur)}</span> },
            ]}
          />
        </Card>

        <Card title="Low / out of stock">
          {low.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted">Nothing low on stock.</p>
          ) : (
            <div className="space-y-3">
              {low.map((p: any, i: number) => (
                <div key={i} className="flex items-center justify-between border-b border-line pb-3 last:border-0 last:pb-0">
                  <div>
                    <div className="text-sm font-medium text-fg">{p.name || "—"}</div>
                    <div className="text-[11px] text-muted">{p.sku || "—"}</div>
                  </div>
                  <div className="text-right">
                    <div className="text-sm font-bold text-warn">{num(p.stock_quantity)} left</div>
                    <Badge tone={p.stock_status === "outofstock" ? "red" : "amber"}>{p.stock_status}</Badge>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
