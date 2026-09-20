"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiDownload } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { CtrCell, DeltaCell, PathCell, SearchToolbar, type SearchWindow } from "@/components/dashboard/search";
import { num } from "@/lib/format";
import { Sparkles } from "lucide-react";

type OpportunityItem = {
  query: string;
  reason: string;
  clicks: number;
  impressions: number;
  ctr: number | null;
  position: number | null;
  clicks_delta: number | null;
  position_delta: number | null;
};

type CannibalizationItem = {
  query: string;
  pages: { page_path: string; clicks: number; impressions: number; position: number | null }[];
  total_clicks: number;
  total_impressions: number;
  is_candidate: boolean;
  reason: string;
};

const OPPORTUNITY_TYPES = [
  { key: "winners", label: "Winners" },
  { key: "losers", label: "Losers" },
  { key: "striking_distance", label: "Striking distance" },
  { key: "ctr_opportunities", label: "CTR opportunities" },
  { key: "content_decay", label: "Content decay" },
  { key: "cannibalization", label: "Cannibalization" },
] as const;

type OpportunityType = (typeof OPPORTUNITY_TYPES)[number]["key"];

const EMPTY_COPY = "Nothing flagged in this window — that's good news.";

export default function SearchOpportunitiesPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const base = siteId ? `/sites/${siteId}/search` : null;

  const [win, setWin] = useState<SearchWindow>(28);
  const [type, setType] = useState<OpportunityType>("winners");
  const [exportError, setExportError] = useState<string | null>(null);

  // One fetch per tab — the `type` param keeps each payload small.
  const req = useApi<any>(base ? `${base}/opportunities?window=${win}&type=${type}` : null);

  function exportCsv() {
    if (!siteId) return;
    setExportError(null);
    apiDownload(`/sites/${siteId}/search/export.csv?type=${type}&window=${win}`, `search-${type}-${win}d.csv`).catch((e: any) =>
      setExportError(e?.message || "Export failed."),
    );
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<Sparkles size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site with the Insightistic plugin to see its search opportunities here."
      />
    );

  const items: any[] = req.data?.[type] || [];

  const columns: Column<OpportunityItem>[] = [
    {
      key: "query",
      label: "Query",
      primary: true,
      render: (r) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-fg">{r.query}</p>
          {/* Doc 10 acceptance: flagged items explain why. */}
          <p className="text-xs text-muted">{r.reason}</p>
        </div>
      ),
    },
    { key: "clicks", label: "Clicks", align: "right", render: (r) => num(r.clicks) },
    { key: "clicks_delta", label: "Δ Clicks", align: "right", render: (r) => <DeltaCell value={r.clicks_delta} /> },
    { key: "impressions", label: "Impressions", align: "right", render: (r) => num(r.impressions) },
    { key: "ctr", label: "CTR", align: "right", render: (r) => <CtrCell value={r.ctr} /> },
    { key: "position", label: "Pos", align: "right", render: (r) => (r.position != null ? Number(r.position).toFixed(1) : "—") },
    { key: "position_delta", label: "Δ Pos", align: "right", render: (r) => <DeltaCell value={r.position_delta} invert /> },
  ];

  return (
    <div>
      <PageHeader title="Opportunities" subtitle={`${site?.name || ""} · last ${win} days vs the previous ${win} days · every flag explains why`} />

      <SearchToolbar window={win} onWindowChange={setWin} onExport={exportCsv} exportType={type} />
      {exportError && <p className="mt-2 text-xs text-bad">{exportError}</p>}

      <Card className="mt-4">
        <div className="mb-4 flex flex-wrap gap-2">
          {OPPORTUNITY_TYPES.map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => setType(t.key)}
              className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${
                type === t.key ? "bg-brand text-white" : "bg-card2 text-muted hover:text-fg"
              }`}
            >
              {t.label}
            </button>
          ))}
        </div>

        {req.loading && !req.data ? (
          <Spinner />
        ) : req.error ? (
          <ErrorState message={req.error} onRetry={req.reload} />
        ) : type === "cannibalization" ? (
          (items as CannibalizationItem[]).length === 0 ? (
            <p className="py-6 text-center text-sm text-muted">{EMPTY_COPY}</p>
          ) : (
            <div>
              {(items as CannibalizationItem[]).map((item, i) => (
                <div key={`${item.query}-${i}`} className="border-b border-line py-3.5 last:border-0">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-fg">{item.query}</p>
                      <p className="text-xs text-muted">{item.reason}</p>
                    </div>
                    <div className="shrink-0 text-right">
                      {item.is_candidate ? (
                        <Badge tone="amber" className="mb-1">
                          Candidate
                        </Badge>
                      ) : null}
                      <p className="text-xs text-muted">
                        {num(item.total_clicks)} clicks · {num(item.total_impressions)} impressions
                      </p>
                    </div>
                  </div>
                  <table className="mt-2 w-full text-xs">
                    <thead>
                      <tr className="text-left text-muted">
                        <th className="pb-1 font-medium">Competing page</th>
                        <th className="pb-1 text-right font-medium">Clicks</th>
                        <th className="pb-1 text-right font-medium">Impr.</th>
                        <th className="pb-1 text-right font-medium">Pos</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                      {(item.pages || []).map((p, j) => (
                        <tr key={`${p.page_path}-${j}`}>
                          <td className="py-1.5 pr-2">
                            <PathCell path={p.page_path} />
                          </td>
                          <td className="py-1.5 text-right text-muted">{num(p.clicks)}</td>
                          <td className="py-1.5 text-right text-muted">{num(p.impressions)}</td>
                          <td className="py-1.5 text-right text-muted">{p.position != null ? Number(p.position).toFixed(1) : "—"}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ))}
            </div>
          )
        ) : (
          <ResponsiveTable columns={columns} rows={items as OpportunityItem[]} rowKey={(r, i) => `${r.query}-${i}`} emptyMessage={EMPTY_COPY} />
        )}
      </Card>
    </div>
  );
}
