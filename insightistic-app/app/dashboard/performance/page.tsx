"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { LineTrend } from "@/components/dashboard/Charts";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { Gauge } from "lucide-react";

const CWV_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  good: "green",
  needs_improvement: "amber",
  poor: "red",
};

function ScoreRing({ label, value }: { label: string; value: number | null }) {
  const tone = value == null ? "text-muted" : value >= 90 ? "text-good" : value >= 50 ? "text-warn" : "text-bad";
  return (
    <div className="flex flex-col items-center gap-1.5 rounded-xl border border-line bg-card2 px-3 py-4">
      <span className={`text-2xl font-bold ${tone}`}>{value ?? "—"}</span>
      <span className="text-center text-xs text-muted">{label}</span>
    </div>
  );
}

export default function PerformancePage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const [strategy, setStrategy] = useState<"mobile" | "desktop">("mobile");
  const base = siteId ? `/sites/${siteId}/analytics/performance` : null;

  const latest = useApi<any>(base ? `${base}/latest?strategy=${strategy}` : null, [strategy]);
  const history = useApi<any>(base ? `${base}/history?strategy=${strategy}` : null, [strategy]);

  if (!siteId)
    return (
      <EmptyState
        icon={<Gauge size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site with the Insightistic plugin to start tracking site speed here."
      />
    );

  if (latest.loading) return <Spinner />;

  const run = latest.data?.run;
  const issues = latest.data?.issues || [];
  const series = (history.data?.runs || [])
    .slice()
    .reverse()
    .map((r: any) => ({ date: r.run_at, v: r.performance_score }));

  return (
    <div>
      <PageHeader
        title="Performance Tracking"
        subtitle={`${site?.name || ""} · sourced from PageSpeed Insights / Lighthouse`}
        right={
          <div className="flex gap-1 rounded-full bg-card2 p-1">
            {(["mobile", "desktop"] as const).map((s) => (
              <button
                key={s}
                onClick={() => setStrategy(s)}
                className={`rounded-full px-3 py-1 text-xs font-semibold capitalize transition ${
                  strategy === s ? "bg-brand text-white" : "text-muted hover:text-fg"
                }`}
              >
                {s}
              </button>
            ))}
          </div>
        }
      />

      {!run ? (
        <EmptyState
          icon={<Gauge size={20} />}
          title="No performance data yet"
          hint="Performance history appears here once the Insightistic plugin has run a PageSpeed test for this site."
        />
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3 sm:grid-cols-5">
            <ScoreRing label="Performance" value={run.performance_score} />
            <ScoreRing label="Accessibility" value={run.accessibility_score} />
            <ScoreRing label="Best practices" value={run.best_practices_score} />
            <ScoreRing label="SEO" value={run.seo_score} />
            <ScoreRing label="AI readiness" value={run.ai_readiness_score} />
          </div>

          <Card className="mt-5" title="Core Web Vitals">
            <div className="grid gap-3 sm:grid-cols-3">
              {[
                { key: "lcp", label: "LCP", value: run.lcp_ms != null ? `${(run.lcp_ms / 1000).toFixed(2)}s` : "—", status: run.lcp_status },
                { key: "inp", label: "INP", value: run.inp_ms != null ? `${run.inp_ms}ms` : "—", status: run.inp_status },
                { key: "cls", label: "CLS", value: run.cls != null ? Number(run.cls).toFixed(3) : "—", status: run.cls_status },
              ].map((v) => (
                <div key={v.key} className="flex items-center justify-between rounded-xl border border-line bg-card2 px-4 py-3">
                  <div>
                    <p className="text-xs font-semibold text-muted">{v.label}</p>
                    <p className="text-lg font-bold text-fg">{v.value}</p>
                  </div>
                  {v.status && <Badge tone={CWV_TONE[v.status] ?? "slate"} dot>{v.status.replace(/_/g, " ")}</Badge>}
                </div>
              ))}
            </div>
            <div className="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted">
              {run.fcp_ms != null && <span>FCP {run.fcp_ms}ms</span>}
              {run.tbt_ms != null && <span>TBT {run.tbt_ms}ms</span>}
              {run.speed_index_ms != null && <span>Speed Index {run.speed_index_ms}ms</span>}
              {run.ttfb_ms != null && <span>TTFB {run.ttfb_ms}ms</span>}
            </div>
          </Card>

          {series.length > 1 && (
            <Card className="mt-5" title="Performance score history">
              <LineTrend data={series} dataKey="v" height={220} />
            </Card>
          )}

          {issues.length > 0 && (
            <Card className="mt-5" title="Top opportunities">
              <div className="space-y-2">
                {issues.map((iss: any) => (
                  <div key={iss.id} className="flex items-center justify-between gap-3 border-b border-line py-2 last:border-0">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-fg">{iss.title}</p>
                      {iss.description && <p className="truncate text-xs text-muted">{iss.description}</p>}
                    </div>
                    {iss.savings_ms != null && <span className="shrink-0 text-xs font-semibold text-muted">-{iss.savings_ms}ms</span>}
                  </div>
                ))}
              </div>
            </Card>
          )}
        </>
      )}
    </div>
  );
}
