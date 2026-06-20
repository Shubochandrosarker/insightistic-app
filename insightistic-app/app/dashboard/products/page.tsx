"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { DataTable } from "@/components/dashboard/DataTable";
import { Spinner } from "@/components/ui/Spinner";
import { money, num } from "@/lib/format";

export default function ProductsPage() {
  const { siteId, period } = useDashboard();
  const { data, loading } = useApi<any>(siteId ? `/sites/${siteId}/analytics/products?period=${period}` : null, [period]);
  if (!siteId) return <p className="text-slate-400">Add a site first.</p>;
  if (loading || !data) return <Spinner />;

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card title="Top products">
        <DataTable head={["Product", "SKU", "Units", "Revenue"]}
          empty="No sales in range."
          rows={(data.top_products || []).map((p: any) => [p.name || "—", p.sku || "—", num(p.units), money(p.revenue)])} />
      </Card>
      <Card title="Low / out of stock">
        <DataTable head={["Product", "SKU", "Qty", "Status"]}
          empty="Nothing low on stock."
          rows={(data.low_stock || []).map((p: any) => [p.name || "—", p.sku || "—", num(p.stock_quantity), p.stock_status])} />
      </Card>
    </div>
  );
}
