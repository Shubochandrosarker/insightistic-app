"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { RevenueLine, OrdersBars } from "@/components/dashboard/Charts";
import { Card } from "@/components/ui/Card";
import { DataTable } from "@/components/dashboard/DataTable";
import { Spinner } from "@/components/ui/Spinner";
import { money, num } from "@/lib/format";

export default function Overview() {
  const { siteId, period } = useDashboard();
  const base = siteId ? `/sites/${siteId}/analytics` : null;
  const ov = useApi<any>(base ? `${base}/overview?period=${period}` : null, [period]);
  const rev = useApi<any>(base ? `${base}/revenue?period=${period}` : null, [period]);
  const ord = useApi<any>(base ? `${base}/orders?period=${period}` : null, [period]);
  const prod = useApi<any>(base ? `${base}/products?period=${period}` : null, [period]);
  const cust = useApi<any>(base ? `${base}/customers?period=${period}` : null, [period]);

  if (!siteId) return <p className="text-slate-400">Add a site to see analytics.</p>;
  if (ov.loading) return <Spinner />;

  const m = ov.data?.metrics || {};
  const d = ov.data?.deltas || {};

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard label="Revenue" value={money(m.revenue)} delta={d.revenue} />
        <KpiCard label="Orders" value={num(m.orders)} delta={d.orders} />
        <KpiCard label="Avg Order Value" value={money(m.average_order_value)} delta={d.average_order_value} />
        <KpiCard label="Refunds" value={num(m.refunds)} delta={d.refunds} />
        <KpiCard label="New Customers" value={num(m.new_customers)} />
        <KpiCard label="Returning" value={num(m.returning_customers)} />
        <KpiCard label="Products Sold" value={num(m.products_sold)} />
        <KpiCard label="Failed Orders" value={num(m.failed_orders)} />
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Revenue">{rev.data ? <RevenueLine data={rev.data.series} /> : <Spinner />}</Card>
        <Card title="Orders vs Failed">{ord.data ? <OrdersBars data={ord.data.series} /> : <Spinner />}</Card>
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Top Products">
          {prod.data ? (
            <DataTable head={["Product", "Units", "Revenue"]}
              empty="No product sales in range."
              rows={(prod.data.top_products || []).map((p: any) => [p.name || "—", num(p.units), money(p.revenue)])} />
          ) : <Spinner />}
        </Card>
        <Card title="Top Customers">
          {cust.data ? (
            <DataTable head={["Customer", "Orders", "Spent"]}
              empty="No customers yet."
              rows={(cust.data.top_customers || []).map((c: any) => [
                `${c.first_name || ""} ${c.last_name || ""}`.trim() || `#${c.external_customer_id}`,
                num(c.order_count), money(c.total_spent),
              ])} />
          ) : <Spinner />}
        </Card>
      </div>
    </div>
  );
}
