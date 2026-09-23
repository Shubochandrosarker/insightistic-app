"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { AreaTrend } from "@/components/dashboard/Charts";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { num } from "@/lib/format";
import { Search } from "lucide-react";

const OPPORTUNITY_LABEL: Record<string, { label: string; tone: "amber" | "red" | "green" | "slate" }> = {
  high_potential: { label: "High potential", tone: "green" },
  declining: { label: "Declining", tone: "red" },
  content_decay: { label: "Content decay", tone: "amber" },
  stable: { label: "Stable", tone: "slate" },
};

/**
 * Search source selector (roadmap doc 11): Bing is a first-class search
 * source, not an afterthought of Google. Combined sums volumes but refuses to
 * average positions/CTR across engines — the API nulls those out and says why
 * via `position_sources`, which we caption instead of pretending it's math.
 */
type SeoSource = "gsc" | "bing" | "combined";

const SOURCES: { key: SeoSource; label: string }[] = [
  { key: "gsc", label: "Google" },
  { key: "bing", label: "Bing" },
  { key: "combined", label: "Combined" },
];

const SOURCE_SUBTITLE: Record<SeoSource, string> = {
  gsc: "sourced from Google Search Console",
  bing: "sourced from Bing Webmaster",
  combined: "Google and Bing combined",
};

const pos = (v: number | null | undefined) => (v != null ? Number(v).toFixed(1) : "—");

type BingWeeklyRow = {
  week_end: string;
  query: string;
  clicks: number;
  impressions: number;
  avg_click_position: number | null;
  avg_impression_position: number | null;
};

const WEEKLY_COLUMNS: Column<BingWeeklyRow>[] = [
  { key: "query", label: "Query", primary: true, render: (r) => <span className="text-sm font-medium">{r.query}</span> },
  { key: "week_end", label: "Week ending", render: (r) => r.week_end },
  { key: "clicks", label: "Clicks", align: "right", render: (r) => num(r.clicks) },
  { key: "impressions", label: "Impressions", align: "right", render: (r) => num(r.impressions) },
  { key: "avg_click_position", label: "Avg click pos", align: "right", render: (r) => pos(r.avg_click_position) },
  { key: "avg_impression_position", label: "Avg impression pos", align: "right", render: (r) => pos(r.avg_impression_position) },
];

export default function SeoPage() {
  const { siteId, period, sites, rangeQuery } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const base = siteId ? `/sites/${siteId}/analytics/seo` : null;
  const router = useRouter();

  const [source, setSource] = useState<SeoSource>("gsc");

  const ov = useApi<any>(base ? `${base}/overview?period=${period}${rangeQuery}&source=${source}` : null, [period, rangeQuery, source]);
  const q = useApi<any>(base && source === "gsc" ? `${base}/queries?period=${period}${rangeQuery}&limit=15` : null, [period, rangeQuery, source]);
  const p = useApi<any>(base && source === "gsc" ? `${base}/pages?period=${period}${rangeQuery}&limit=15` : null, [period, rangeQuery, source]);
  // Bing weekly buckets are only relevant to the Bing view — don't fetch them otherwise.
  const weekly = useApi<any>(base && source === "bing" ? `${base}/bing?window=90` : null, [source]);

  if (!siteId)
    return (
      <EmptyState
        icon={<Search size={20} />}
        title="No website yet"
        hint="Add a website and connect Search Console to start seeing search visibility here."
      />
    );

  if (ov.loading) return <Spinner />;

  // A failed overview fetch is not the same as an empty site — say so and offer a retry.
  if (ov.error)
    return (
      <div>
        <PageHeader
          title="SEO Analytics"
          subtitle={`${site?.name || ""} · ${SOURCE_SUBTITLE[source]}`}
          right={<SourceToggle value={source} onChange={setSource} />}
        />
        <ErrorState message={ov.error} onRetry={ov.reload} />
      </div>
    );

  const m = ov.data?.metrics || {};
  const d = ov.data?.deltas || {};
  const series = ov.data?.series || [];
  const hasData = ov.data?.has_data;

  return (
    <div>
      <PageHeader
        title="SEO Analytics"
        subtitle={`${site?.name || ""} · ${SOURCE_SUBTITLE[source]}`}
        right={<SourceToggle value={source} onChange={setSource} />}
      />

      {!hasData ? (
        source === "bing" ? (
          <EmptyState
            icon={<Search size={20} />}
            title="No Bing data yet"
            hint="Connect Bing Webmaster in Integrations to start collecting Bing search data for this site."
            action={
              <Button onClick={() => router.push("/dashboard/integrations")}>Connect Bing Webmaster</Button>
            }
          />
        ) : (
          <EmptyState
            icon={<Search size={20} />}
            title="No search data yet"
            hint={
              source === "combined"
                ? "Search analytics appear here once Search Console or Bing Webmaster is connected and synced for this site."
                : "Search analytics appear here once Search Console is connected and synced for this site."
            }
          />
        )
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <KpiCard label="Clicks" value={num(m.clicks)} delta={d.clicks} />
            <KpiCard label="Impressions" value={num(m.impressions)} delta={d.impressions} />
            <KpiCard label="Avg CTR" value={m.ctr != null ? `${m.ctr}%` : "—"} delta={d.ctr} />
            <KpiCard label="Avg position" value={m.avg_position != null ? Number(m.avg_position).toFixed(1) : "—"} delta={d.avg_position != null ? -d.avg_position : null} />
          </div>

          {ov.data?.position_sources && (
            <p className="mt-2 text-xs text-muted" title={String(ov.data.position_sources)}>
              Position hidden — Google and Bing report differently
            </p>
          )}

          <Card className="mt-5" title="Clicks trend">
            {series.length > 1 ? (
              <AreaTrend data={series} dataKey="clicks" color="#7C5CFF" height={260} />
            ) : (
              <p className="py-8 text-center text-sm text-muted">Not enough data yet for a trend.</p>
            )}
          </Card>

          {source === "bing" ? (
            <Card
              className="mt-5"
              title="Bing query stats (weekly)"
              subtitle="Top queries by impressions · Bing reports in weekly buckets"
            >
              {weekly.loading ? (
                <Spinner />
              ) : weekly.error && !weekly.data ? (
                <ErrorState message={weekly.error} onRetry={weekly.reload} />
              ) : (
                <ResponsiveTable
                  columns={WEEKLY_COLUMNS}
                  rows={(weekly.data?.weekly || []) as BingWeeklyRow[]}
                  rowKey={(r, i) => `${r.query}-${r.week_end}-${i}`}
                  empty="No Bing query data for this window yet."
                />
              )}
            </Card>
          ) : source === "gsc" ? (
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
          ) : null}
        </>
      )}
    </div>
  );
}

/** Segmented Google | Bing | Combined selector, mirroring the dimension pills used across analytics pages. */
function SourceToggle({ value, onChange }: { value: SeoSource; onChange: (s: SeoSource) => void }) {
  return (
    <div className="flex flex-wrap gap-2" aria-label="Search source">
      {SOURCES.map((s) => (
        <button
          key={s.key}
          type="button"
          aria-pressed={value === s.key}
          onClick={() => onChange(s.key)}
          className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${
            value === s.key ? "bg-brand text-white" : "bg-card2 text-muted hover:text-fg"
          }`}
        >
          {s.label}
        </button>
      ))}
    </div>
  );
}
