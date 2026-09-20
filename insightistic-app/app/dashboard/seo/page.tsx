"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { AreaTrend } from "@/components/dashboard/Charts";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";
import { Search } from "lucide-react";

const OPPORTUNITY_LABEL: Record<string, { label: string; tone: "amber" | "red" | "green" | "slate" }> = {
  high_potential: { label: "High potential", tone: "green" },
  declining: { label: "Declining", tone: "red" },
  content_decay: { label: "Content decay", tone: "amber" },
  stable: { label: "Stable", tone: "slate" },
};

export default function SeoPage() {
  const { siteId, period, sites, rangeQuery } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const base = siteId ? `/sites/${siteId}/analytics/seo` : null;

  const ov = useApi<any>(base ? `${base}/overview?period=${period}${rangeQuery}` : null, [period, rangeQuery]);
  const q = useApi<any>(base ? `${base}/queries?period=${period}${rangeQuery}&limit=15` : null, [period, rangeQuery]);
  const p = useApi<any>(base ? `${base}/pages?period=${period}${rangeQuery}&limit=15` : null, [period, rangeQuery]);

  if (!siteId)
    return (
      <EmptyState
        icon={<Search size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site with the Insightistic plugin to start seeing search visibility here."
      />
    );

  if (ov.loading) return <Spinner />;

  // A failed overview fetch is not the same as an empty site — say so and offer a retry.
  if (ov.error)
    return (
      <div>
        <PageHeader title="SEO Analytics" subtitle={`${site?.name || ""} · sourced from Google Search Console`} />
        <ErrorState message={ov.error} onRetry={ov.reload} />
      </div>
    );

  const m = ov.data?.metrics || {};
  const d = ov.data?.deltas || {};
  const series = ov.data?.series || [];
  const hasData = ov.data?.has_data;

  return (
    <div>
      <PageHeader title="SEO Analytics" subtitle={`${site?.name || ""} · sourced from Google Search Console`} />

      {!hasData ? (
        <EmptyState
          icon={<Search size={20} />}
          title="No search data yet"
          hint="SEO analytics appear here once your site's Search Console data has synced through the Insightistic plugin."
        />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <KpiCard label="Clicks" value={num(m.clicks)} delta={d.clicks} />
            <KpiCard label="Impressions" value={num(m.impressions)} delta={d.impressions} />
            <KpiCard label="Avg CTR" value={m.ctr != null ? `${m.ctr}%` : "—"} delta={d.ctr} />
            <KpiCard label="Avg position" value={m.avg_position != null ? Number(m.avg_position).toFixed(1) : "—"} delta={d.avg_position != null ? -d.avg_position : null} />
          </div>

          <Card className="mt-5" title="Clicks trend">
            {series.length > 1 ? (
              <AreaTrend data={series} dataKey="clicks" color="#7C5CFF" height={260} />
            ) : (
              <p className="py-8 text-center text-sm text-muted">Not enough data yet for a trend.</p>
            )}
          </Card>

          <div className="mt-5 grid gap-5 lg:grid-cols-2">
            <Card title="Top queries">
              {q.loading ? (
                <Spinner />
              ) : q.error && !q.data ? (
                <ErrorState message={q.error} onRetry={q.reload} />
              ) : (q.data?.queries || []).length === 0 ? (
                <p className="py-6 text-center text-sm text-muted">No query data for this period yet.</p>
              ) : (
                <div className="space-y-2">
                  {q.data.queries.map((row: any, i: number) => {
                    const opp = row.opportunity ? OPPORTUNITY_LABEL[row.opportunity] : null;
                    return (
                      <div key={i} className="flex items-center justify-between gap-3 border-b border-line py-2 last:border-0">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium text-fg">{row.query}</p>
                          <p className="text-xs text-muted">{num(row.impressions)} impressions · {num(row.clicks)} clicks · pos {row.position != null ? Number(row.position).toFixed(1) : "—"}</p>
                        </div>
                        {opp && <Badge tone={opp.tone} className="shrink-0">{opp.label}</Badge>}
                      </div>
                    );
                  })}
                </div>
              )}
            </Card>

            <Card title="Top pages">
              {p.loading ? (
                <Spinner />
              ) : p.error && !p.data ? (
                <ErrorState message={p.error} onRetry={p.reload} />
              ) : (p.data?.pages || []).length === 0 ? (
                <p className="py-6 text-center text-sm text-muted">No page data for this period yet.</p>
              ) : (
                <div className="space-y-2">
                  {p.data.pages.map((row: any, i: number) => (
                    <div key={i} className="border-b border-line py-2 last:border-0">
                      <p className="truncate text-sm font-medium text-fg">{row.page_path}</p>
                      <p className="text-xs text-muted">{num(row.impressions)} impressions · {num(row.clicks)} clicks · pos {row.position != null ? Number(row.position).toFixed(1) : "—"}</p>
                    </div>
                  ))}
                </div>
              )}
            </Card>
          </div>
        </>
      )}
    </div>
  );
}
