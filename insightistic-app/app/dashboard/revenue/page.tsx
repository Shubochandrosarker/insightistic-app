"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { AreaTrend, Bars, Donut } from "@/components/dashboard/Charts";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { money0, num } from "@/lib/format";
import { TrendingUp } from "lucide-react";

const SEG_COLORS = ["#00C04B", "#00D084", "#F6A609", "#6C5CE7", "#9FB4AD"];

export default function RevenuePage() {
  const { siteId, period, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const cur = site?.currency as string | undefined;
  const rev = useApi<any>(siteId ? `/sites/${siteId}/analytics/revenue?period=${period}` : null, [period]);
  const ord = useApi<any>(siteId ? `/sites/${siteId}/orders` : null);

  if (!siteId) return <EmptyState icon={<TrendingUp size={20} />} title="No site connected" hint="Connect a store to see revenue analytics." />;
  if (rev.loading || !rev.data) return <Spinner />;

  const series = rev.data.series || [];
  const total = series.reduce((s: number, r: any) => s + (r.revenue || 0), 0);
  const orders = series.reduce((s: number, r: any) => s + (r.orders || 0), 0);
  const refunds = series.reduce((s: number, r: any) => s + (r.refunds || 0), 0);
  const perDay = series.length ? total / series.length : 0;

  // Group daily revenue into ~weekly buckets for the bar chart.
  const weeks: { label: string; v: number }[] = [];
  for (let i = 0; i < series.length; i += 7) {
    const chunk = series.slice(i, i + 7);
    weeks.push({ label: `W${weeks.length + 1}`, v: chunk.reduce((s: number, r: any) => s + (r.revenue || 0), 0) });
  }

  // Payment methods from the recent order feed.
  const pmMap: Record<string, number> = {};
  (ord.data?.orders || []).forEach((o: any) => {
    const k = o.payment_method || "Other";
    pmMap[k] = (pmMap[k] || 0) + 1;
  });
  const pm = Object.entries(pmMap).map(([name, value]) => ({ name, value })).sort((a, b) => b.value - a.value);
  const pmTotal = pm.reduce((s, x) => s + x.value, 0);

  return (
    <div>
      <PageHeader title="Revenue" subtitle={`${site?.name || ""} · ${period.replace(/_/g, " ")}`} />

      <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
        {[
          { label: "Total revenue", value: money0(total, cur) },
          { label: "Orders", value: num(orders) },
          { label: "Refunds", value: num(refunds) },
          { label: "Avg / day", value: money0(perDay, cur) },
        ].map((k) => (
          <div key={k.label} className="rounded-2xl border border-line bg-card p-4 shadow-card">
            <div className="text-xs font-medium text-muted">{k.label}</div>
            <div className="mt-1.5 text-[26px] font-bold text-fg">{k.value}</div>
          </div>
        ))}
      </div>

      <Card className="mt-5" title="Revenue trend" subtitle={`Daily revenue · ${period.replace(/_/g, " ")}`}>
        <AreaTrend data={series} dataKey="revenue" />
      </Card>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        <Card title="Revenue by week">
          {weeks.length ? <Bars data={weeks} dataKey="v" /> : <p className="py-10 text-center text-sm text-muted">Not enough data.</p>}
        </Card>
        <Card title="Payment methods" subtitle="From recent orders">
          {pm.length ? (
            <>
              <Donut data={pm} />
              <div className="mt-3 space-y-2">
                {pm.slice(0, 5).map((p, i) => (
                  <div key={p.name} className="flex items-center justify-between text-sm">
                    <span className="flex items-center gap-2 text-muted">
                      <span className="h-2.5 w-2.5 rounded-full" style={{ background: SEG_COLORS[i % SEG_COLORS.length] }} /> {p.name}
                    </span>
                    <span className="font-semibold text-fg">{pmTotal ? Math.round((p.value / pmTotal) * 100) : 0}%</span>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <p className="py-10 text-center text-sm text-muted">No payment data yet.</p>
          )}
        </Card>
      </div>
    </div>
  );
}
