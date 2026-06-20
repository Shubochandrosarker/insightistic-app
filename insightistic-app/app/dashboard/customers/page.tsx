"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { DataTable } from "@/components/dashboard/DataTable";
import { Spinner } from "@/components/ui/Spinner";
import { money, num } from "@/lib/format";

export default function CustomersPage() {
  const { siteId, period } = useDashboard();
  const { data, loading } = useApi<any>(siteId ? `/sites/${siteId}/analytics/customers?period=${period}` : null, [period]);
  if (!siteId) return <p className="text-slate-400">Add a site first.</p>;
  if (loading || !data) return <Spinner />;

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard label="New customers" value={num(data.new_customers)} />
        <KpiCard label="Returning" value={num(data.returning_customers)} />
      </div>
      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Top customers">
          <DataTable head={["Customer", "Orders", "Spent"]}
            empty="No customers yet."
            rows={(data.top_customers || []).map((c: any) => [
              `${c.first_name || ""} ${c.last_name || ""}`.trim() || `#${c.external_customer_id}`,
              num(c.order_count), money(c.total_spent),
            ])} />
        </Card>
        <Card title="By country">
          <DataTable head={["Country", "Customers", "Spent"]}
            empty="No location data."
            rows={(data.by_country || []).map((r: any) => [r.country, num(r.customers), money(r.spent)])} />
        </Card>
      </div>
    </div>
  );
}
