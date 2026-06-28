"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { AreaTrend, Donut } from "@/components/dashboard/Charts";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { money0, money, num, timeAgo } from "@/lib/format";
import { Sparkles, Package, AlertTriangle } from "lucide-react";

const PERIOD_LABEL: Record<string, string> = {
  today: "Today", yesterday: "Yesterday", last_7_days: "Last 7 days",
  last_30_days: "Last 30 days", this_month: "This month", last_month: "Last month",
};

export default function Overview() {
  const { siteId, period, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const base = siteId ? `/sites/${siteId}/analytics` : null;

  const ov = useApi<any>(base ? `${base}/overview?period=${period}` : null, [period]);
  const rev = useApi<any>(base ? `${base}/revenue?period=${period}` : null, [period]);
  const prod = useApi<any>(base ? `${base}/products?period=${period}` : null, [period]);
  const cust = useApi<any>(base ? `${base}/customers?period=${period}` : null, [period]);
  const ins = useApi<any>(siteId ? `/sites/${siteId}/insights` : null);

  const [busy, setBusy] = useState(false);

  if (!siteId)
    return (
      <EmptyState
        icon={<Package size={20} />}
        title="No site connected yet"
        hint="Add a WooCommerce or WordPress site in Settings, then install the connector to start seeing analytics here."
      />
    );

  if (ov.loading) return <Spinner />;

  const m = ov.data?.metrics || {};
  const d = ov.data?.deltas || {};
  const series = rev.data?.series || [];
  const spark = (key: string) => series.map((r: any) => ({ v: r[key] || 0 }));
  const cur = site?.currency as string | undefined;

  const newC = cust.data?.new_customers ?? 0;
  const retC = cust.data?.returning_customers ?? 0;
  const segments = [
    { name: "Returning", value: retC },
    { name: "New", value: newC },
  ].filter((s) => s.value > 0);

  const topInsight = (ins.data?.insights || [])[0];

  // Derived alerts from real data (low stock + refunds + risky insights).
  const alerts: { tone: "red" | "amber" | "slate"; title: string; note: string }[] = [];
  (prod.data?.low_stock || []).slice(0, 2).forEach((p: any) =>
    alerts.push({ tone: "amber", title: `${p.name || "Product"} low on stock`, note: `${num(p.stock_quantity)} left · ${p.stock_status}` }));
  if ((m.refunds || 0) > 0)
    alerts.push({ tone: "red", title: "Refunds in this period", note: `${num(m.refunds)} refunds — review affected products` });
  if ((m.failed_orders || 0) > 0)
    alerts.push({ tone: "amber", title: "Failed orders detected", note: `${num(m.failed_orders)} failed — check the payment gateway` });

  async function generateSummary() {
    setBusy(true);
    try { await apiPost(`/sites/${siteId}/insights/generate`, { type: "weekly" }); ins.reload(); }
    catch {}
    finally { setBusy(false); }
  }

  return (
    <div>
      <PageHeader
        title="Overview"
        subtitle={`${site?.name || ""} · ${PERIOD_LABEL[period] || period}${site?.last_sync_at ? ` · last synced ${timeAgo(site.last_sync_at)}` : ""}`}
        right={
          <button
            onClick={generateSummary}
            disabled={busy}
            className="inline-flex items-center gap-2 rounded-xl bg-violet px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_20px_-8px_rgba(108,92,231,0.6)] hover:opacity-90 disabled:opacity-50"
          >
            <Sparkles size={16} /> {busy ? "Generating…" : "Generate AI summary"}
          </button>
        }
      />

      {/* AI summary banner */}
      <div className="mb-5 rounded-2xl border border-violet/20 bg-gradient-to-r from-violet/[0.08] to-brand/[0.06] p-5">
        <div className="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wide text-violet">
          <Sparkles size={14} /> AI business summary
        </div>
        {topInsight ? (
          <>
            <p className="mt-2 text-sm leading-relaxed text-fg">
              <span className="font-semibold">{topInsight.title}.</span> {topInsight.summary}
            </p>
            {topInsight.recommendation && (
              <p className="mt-1.5 text-sm text-muted">
                <span className="font-semibold text-violet">Next action:</span> {topInsight.recommendation}
              </p>
            )}
          </>
        ) : (
          <p className="mt-2 text-sm text-muted">
            No AI summary yet for {site?.name || "this store"}. Click “Generate AI summary” to get a plain-language read on
            what changed, why it matters, and what to do next.
          </p>
        )}
      </div>

      {/* KPI grid */}
      <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
        <KpiCard label="Revenue" value={money0(m.revenue, cur)} delta={d.revenue} series={spark("revenue")} />
        <KpiCard label="Orders" value={num(m.orders)} delta={d.orders} series={spark("orders")} />
        <KpiCard label="Avg order value" value={money(m.average_order_value, cur)} delta={d.average_order_value} color="#6C5CE7" />
        <KpiCard label="Refunds" value={num(m.refunds)} delta={d.refunds} series={spark("refunds")} color="#EF5350" />
        <KpiCard label="New customers" value={num(m.new_customers)} delta={d.new_customers} />
        <KpiCard label="Returning" value={num(m.returning_customers)} delta={d.returning_customers} />
        <KpiCard label="Products sold" value={num(m.products_sold)} delta={d.products_sold} />
        <KpiCard label="Failed orders" value={num(m.failed_orders)} delta={d.failed_orders} color="#EF5350" />
      </div>

      {/* Trend + segments */}
      <div className="mt-5 grid gap-5 lg:grid-cols-3">
        <Card className="lg:col-span-2" title="Revenue trend" subtitle={`Daily revenue · ${PERIOD_LABEL[period] || period}`}>
          {rev.loading ? <Spinner /> : <AreaTrend data={series} dataKey="revenue" />}
        </Card>
        <Card title="Customer segments" subtitle="Share of customers">
          {segments.length ? (
            <>
              <Donut data={segments} centerValue={num(m.orders)} centerLabel="orders" />
              <div className="mt-3 space-y-2">
                {[{ n: "Returning", v: retC, c: "#00C04B" }, { n: "New", v: newC, c: "#00D084" }].map((s) => (
                  <div key={s.n} className="flex items-center justify-between text-sm">
                    <span className="flex items-center gap-2 text-muted">
                      <span className="h-2.5 w-2.5 rounded-full" style={{ background: s.c }} /> {s.n}
                    </span>
                    <span className="font-semibold text-fg">{num(s.v)}</span>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <p className="py-10 text-center text-sm text-muted">No customer data in range.</p>
          )}
        </Card>
      </div>

      {/* Top products + alerts */}
      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        <Card title="Top products">
          <div className="space-y-1">
            {(prod.data?.top_products || []).slice(0, 5).map((p: any, i: number) => (
              <div key={i} className="flex items-center justify-between border-b border-line py-2.5 last:border-0">
                <div>
                  <div className="text-sm font-medium text-fg">{p.name || "—"}</div>
                  <div className="text-[11px] text-muted">{p.sku || `#${p.product_id}`}</div>
                </div>
                <div className="text-right">
                  <div className="text-sm font-semibold text-fg">{money(p.revenue, cur)}</div>
                  <div className="text-[11px] text-muted">{num(p.units)} sold</div>
                </div>
              </div>
            ))}
            {(!prod.data?.top_products || prod.data.top_products.length === 0) && (
              <p className="py-6 text-center text-sm text-muted">No product sales in range.</p>
            )}
          </div>
        </Card>

        <Card title="Alerts" subtitle="Things that may need attention">
          <div className="space-y-2.5">
            {alerts.length === 0 && (
              <p className="py-6 text-center text-sm text-muted">All clear — no alerts right now.</p>
            )}
            {alerts.map((a, i) => (
              <div key={i} className="flex items-start gap-3 rounded-xl border border-line p-3">
                <span className={`mt-0.5 flex h-7 w-7 items-center justify-center rounded-lg ${a.tone === "red" ? "bg-bad/12 text-bad" : a.tone === "amber" ? "bg-warn/15 text-warn" : "bg-black/5 text-muted"}`}>
                  <AlertTriangle size={14} />
                </span>
                <div>
                  <div className="text-sm font-medium text-fg">{a.title}</div>
                  <div className="text-xs text-muted">{a.note}</div>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
}
