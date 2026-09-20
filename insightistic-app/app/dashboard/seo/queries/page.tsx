"use client";
import { useEffect, useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiDownload, apiGet } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { CtrCell, DeltaCell, SearchToolbar, ValueWithPrev, searchListParams, type SearchWindow } from "@/components/dashboard/search";
import { num } from "@/lib/format";
import { Search } from "lucide-react";

type SearchRow = {
  query: string;
  clicks: number;
  impressions: number;
  ctr: number | null;
  position: number | null;
  prev_clicks: number | null;
  prev_impressions: number | null;
  prev_position: number | null;
  clicks_delta: number | null;
  impressions_delta: number | null;
  position_delta: number | null;
};

const SORTS = [
  { key: "clicks", label: "Clicks" },
  { key: "impressions", label: "Impressions" },
  { key: "ctr", label: "CTR" },
  { key: "position", label: "Position" },
  { key: "clicks_delta", label: "Click change" },
  { key: "position_delta", label: "Position change" },
];

const selectCls = "rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand";

export default function SearchQueriesPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const base = siteId ? `/sites/${siteId}/search` : null;

  const [win, setWin] = useState<SearchWindow>(28);
  const [sort, setSort] = useState("clicks");
  const [dir, setDir] = useState("desc");
  // Search inputs are debounced (400ms) so typing doesn't fire a request per keystroke.
  const [qInput, setQInput] = useState("");
  const [minImprInput, setMinImprInput] = useState("");
  const [filters, setFilters] = useState<{ q: string; minImpr: number | null }>({ q: "", minImpr: null });
  useEffect(() => {
    const t = setTimeout(() => {
      const parsed = minImprInput.trim() ? Math.max(0, parseInt(minImprInput, 10) || 0) : null;
      setFilters((prev) => (prev.q === qInput.trim() && prev.minImpr === parsed ? prev : { q: qInput.trim(), minImpr: parsed }));
    }, 400);
    return () => clearTimeout(t);
  }, [qInput, minImprInput]);

  const path = base ? `${base}/queries?${searchListParams({ window: win, q: filters.q, minImpressions: filters.minImpr, sort, dir })}` : null;
  const first = useApi<any>(path);

  // Cursor pagination: the first page streams through useApi; "Load more"
  // fetches the next cursor directly and appends.
  const [extra, setExtra] = useState<SearchRow[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreError, setMoreError] = useState<string | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);

  useEffect(() => {
    setExtra([]);
    setMoreError(null);
    setCursor(first.data?.meta?.next_cursor ?? null);
    setHasMore(!!first.data?.meta?.has_more);
  }, [first.data]);

  const rows: SearchRow[] = [...(first.data?.data || []), ...extra];

  function changeSort(s: string) {
    setSort(s);
    // Best position first is the natural reading for a position sort.
    setDir(s === "position" ? "asc" : "desc");
  }

  async function loadMore() {
    if (!base || !cursor || loadingMore) return;
    setLoadingMore(true);
    setMoreError(null);
    try {
      const next = await apiGet(`${base}/queries?${searchListParams({ window: win, q: filters.q, minImpressions: filters.minImpr, sort, dir, cursor })}`);
      setExtra((prev) => [...prev, ...(next?.data || [])]);
      setCursor(next?.meta?.next_cursor ?? null);
      setHasMore(!!next?.meta?.has_more);
    } catch (e: any) {
      setMoreError(e?.message || "Couldn't load more rows.");
    } finally {
      setLoadingMore(false);
    }
  }

  function exportCsv() {
    if (!siteId) return;
    setExportError(null);
    apiDownload(`/sites/${siteId}/search/export.csv?type=queries&window=${win}`, `search-queries-${win}d.csv`).catch((e: any) =>
      setExportError(e?.message || "Export failed."),
    );
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<Search size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site with the Insightistic plugin to explore its Search Console queries here."
      />
    );

  const columns: Column<SearchRow>[] = [
    {
      key: "query",
      label: "Query",
      primary: true,
      render: (r) => <span className="font-medium text-fg">{r.query}</span>,
    },
    { key: "clicks", label: "Clicks", align: "right", render: (r) => <ValueWithPrev main={num(r.clicks)} prev={r.prev_clicks != null ? num(r.prev_clicks) : null} /> },
    { key: "clicks_delta", label: "Δ Clicks", align: "right", render: (r) => <DeltaCell value={r.clicks_delta} /> },
    {
      key: "impressions",
      label: "Impressions",
      align: "right",
      render: (r) => <ValueWithPrev main={num(r.impressions)} prev={r.prev_impressions != null ? num(r.prev_impressions) : null} />,
    },
    { key: "impressions_delta", label: "Δ Impr", align: "right", render: (r) => <DeltaCell value={r.impressions_delta} /> },
    { key: "ctr", label: "CTR", align: "right", render: (r) => <CtrCell value={r.ctr} /> },
    {
      key: "position",
      label: "Pos",
      align: "right",
      render: (r) => <ValueWithPrev main={r.position != null ? Number(r.position).toFixed(1) : "—"} prev={r.prev_position != null ? Number(r.prev_position).toFixed(1) : null} />,
    },
    { key: "position_delta", label: "Δ Pos", align: "right", render: (r) => <DeltaCell value={r.position_delta} invert /> },
  ];

  return (
    <div>
      <PageHeader title="Queries explorer" subtitle={`${site?.name || ""} · last ${win} days vs the previous ${win} days, from Google Search Console`} />

      <SearchToolbar window={win} onWindowChange={setWin} onExport={exportCsv} exportType="queries" showBackfill siteId={siteId} />
      {exportError && <p className="mt-2 text-xs text-bad">{exportError}</p>}

      <Card className="mt-4">
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <input
            value={qInput}
            onChange={(e) => setQInput(e.target.value)}
            placeholder="Search queries…"
            className="w-56 rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"
            aria-label="Search queries"
          />
          <input
            type="number"
            min={0}
            value={minImprInput}
            onChange={(e) => setMinImprInput(e.target.value)}
            placeholder="Min impressions"
            className="w-36 rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"
            aria-label="Minimum impressions"
          />
          <select value={sort} onChange={(e) => changeSort(e.target.value)} className={selectCls} aria-label="Sort by">
            {SORTS.map((s) => (
              <option key={s.key} value={s.key}>
                Sort: {s.label}
              </option>
            ))}
          </select>
          <select value={dir} onChange={(e) => setDir(e.target.value)} className={selectCls} aria-label="Sort direction">
            <option value="desc">High → low</option>
            <option value="asc">Low → high</option>
          </select>
        </div>

        {first.loading && !first.data ? (
          <Spinner />
        ) : first.error ? (
          <ErrorState message={first.error} onRetry={first.reload} />
        ) : (
          <>
            <ResponsiveTable
              columns={columns}
              rows={rows}
              rowKey={(r, i) => `${r.query}-${i}`}
              emptyMessage="No query data for this window — sync GSC or widen the window."
            />
            {moreError && <p className="mt-3 text-center text-xs text-bad">{moreError}</p>}
            {hasMore && (
              <div className="mt-4 flex justify-center">
                <button
                  onClick={loadMore}
                  disabled={loadingMore}
                  className="rounded-xl border border-line bg-card px-4 py-2 text-sm font-semibold text-fg transition hover:border-brand/40 hover:bg-card2 disabled:opacity-50"
                >
                  {loadingMore ? "Loading…" : "Load more"}
                </button>
              </div>
            )}
          </>
        )}
      </Card>
    </div>
  );
}
