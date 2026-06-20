"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { RevenueLine } from "@/components/dashboard/Charts";
import { DataTable } from "@/components/dashboard/DataTable";
import { Spinner } from "@/components/ui/Spinner";
import { money, num } from "@/lib/format";

export default function RevenuePage() {
  const { siteId, period } = useDashboard();
  const { data, loading } = useApi<any>(siteId ? `/sites/${siteId}/analytics/revenue?period=${period}` : null, [period]);
  if (!siteId) return <p className="text-slate-400">Add a site first.</p>;
  if (loading || !data) return <Spinner />;
  const series = data.series || [];
  const total = series.reduce((s: number, r: any) => s + (r.revenue || 0), 0);

  return (
    <div className="space-y-5">
      <Card title={`Revenue · ${money(total)} total`}>
        <RevenueLine data={series} />
      </Card>
      <Card title="Daily breakdown">
        <DataTable head={["Date", "Revenue", "Orders", "Refunds"]}
          rows={series.map((r: any) => [r.date, money(r.revenue), num(r.orders), num(r.refunds)])} />
      </Card>
    </div>
  );
}
