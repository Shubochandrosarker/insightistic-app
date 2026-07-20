"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { AreaTrend } from "@/components/dashboard/Charts";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";
import { Globe, TrendingUp } from "lucide-react";

const DIMENSIONS: { key: string; label: string }[] = [
  { key: "channel", label: "Channels" },
  { key: "source_medium", label: "Source / Medium" },
  { key: "country", label: "Countries" },
  { key: "device", label: "Devices" },
  { key: "landing_page", label: "Landing pages" },
];

export default function TrafficPage() {
  const { siteId, period, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const base = siteId ? `/sites/${siteId}/analytics/traffic` : null;

  const ov = useApi<any>(base ? `${base}/overview?period=${period}` : null, [period]);
  const [dimension, setDimension] = useState("channel");
  const dim = useApi<any>(base ? `${base}/dimensions/${dimension}?period=${period}` : null, [period, dimension]);

  if (!siteId)
    return (
      <EmptyState
        icon={<Globe size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site with the Insightistic plugin to start seeing traffic analytics here."
      />
    );

  if (ov.loading) return <Spinner />;

  const m = ov.data?.metrics || {};
  const d = ov.data?.deltas || {};
  const series = ov.data?.series || [];
  const hasData = ov.data?.has_data;

  return (
    <div>
      <PageHeader title="Traffic Analytics" subtitle={`${site?.name || ""} · sourced from Google Analytics 4`} />

      {!hasData ? (
        <EmptyState
          icon={<TrendingUp size={20} />}
          title="No traffic data yet"
          hint="Traffic analytics appear here once your site's GA4 data has synced through the Insightistic plugin. This can take up to a day after connecting."
        />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <KpiCard label="Users" value={num(m.users)} delta={d.users} />
            <KpiCard label="Sessions" value={num(m.sessions)} delta={d.sessions} />
            <KpiCard label="Page views" value={num(m.page_views)} delta={d.page_views} />
            <KpiCard label="Engagement rate" value={m.engagement_rate != null ? `${m.engagement_rate}%` : "—"} delta={d.engagement_rate} />
            <KpiCard label="Conversions" value={num(m.conversions)} delta={d.conversions} />
            <KpiCard label="Transactions" value={num(m.transactions)} delta={d.transactions} />
          </div>

          <Card className="mt-5" title="Sessions trend">
            {series.length > 1 ? (
              <AreaTrend data={series} dataKey="sessions" height={260} />
            ) : (
              <p className="py-8 text-center text-sm text-muted">Not enough data yet for a trend.</p>
            )}
          </Card>

          <Card className="mt-5" title="Breakdown">
            <div className="mb-4 flex flex-wrap gap-2">
              {DIMENSIONS.map((dOpt) => (
                <button
                  key={dOpt.key}
                  onClick={() => setDimension(dOpt.key)}
                  className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${
                    dimension === dOpt.key ? "bg-brand text-white" : "bg-card2 text-muted hover:text-fg"
                  }`}
                >
                  {dOpt.label}
                </button>
              ))}
            </div>

            {dim.loading ? (
              <Spinner />
            ) : (dim.data?.rows || []).length === 0 ? (
              <p className="py-8 text-center text-sm text-muted">No breakdown data for this period yet.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-muted">
                      <th className="pb-2 font-medium">{DIMENSIONS.find((x) => x.key === dimension)?.label}</th>
                      <th className="pb-2 font-medium">Sessions</th>
                      <th className="pb-2 font-medium">Users</th>
                      <th className="pb-2 font-medium">Conversions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {dim.data.rows.map((r: any, i: number) => (
                      <tr key={i}>
                        <td className="py-2 font-medium text-fg">{r.title || r.dimension_value}</td>
                        <td className="py-2 text-muted">{num(r.sessions)}</td>
                        <td className="py-2 text-muted">{num(r.users)}</td>
                        <td className="py-2 text-muted">{num(r.conversions)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}
