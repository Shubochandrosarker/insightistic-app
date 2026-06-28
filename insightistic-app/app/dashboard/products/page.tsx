"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
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
          {top.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted">No product sales in range.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-[11px] uppercase tracking-wide text-muted">
                    <th className="border-b border-line pb-2.5 px-2 text-left font-semibold">Product</th>
                    <th className="border-b border-line pb-2.5 px-2 text-right font-semibold">Sold</th>
                    <th className="border-b border-line pb-2.5 px-2 text-right font-semibold">Revenue</th>
                  </tr>
                </thead>
                <tbody>
                  {top.map((p: any, i: number) => (
                    <tr key={i} className="group">
                      <td className="border-b border-line py-3 px-2 group-last:border-0">
                        <div className="font-medium text-fg">{p.name || "—"}</div>
                        <div className="text-[11px] text-muted">{p.sku || `#${p.product_id}`}</div>
                      </td>
                      <td className="border-b border-line py-3 px-2 text-right text-fg group-last:border-0">{num(p.units)}</td>
                      <td className="border-b border-line py-3 px-2 text-right font-semibold text-fg group-last:border-0">{money(p.revenue, cur)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
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
