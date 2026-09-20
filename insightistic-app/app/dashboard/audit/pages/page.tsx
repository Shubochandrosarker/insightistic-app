"use client";
import { useEffect, useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiDownload, apiGet } from "@/lib/api";
import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { PathCell } from "@/components/dashboard/search";
import { RunBar, crawlListParams, urlPath, useLatestRun } from "@/components/dashboard/audit";
import { num } from "@/lib/format";
import { FileSearch } from "lucide-react";

/**
 * Crawled pages explorer (roadmap doc 12): every URL the crawler saw with its
 * HTTP status, metadata and content stats. Scoped to the latest run, with the
 * ok/error filter, a debounced search, and the pages CSV export.
 */

type CrawlPageRow = {
  url: string;
  status_code: number | null;
  indexable: boolean;
  title: string | null;
  meta_description: string | null;
  word_count: number | null;
  h1_count: number | null;
  canonical_url: string | null;
  response_ms: number | null;
};

const selectCls = "rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand";

export default function AuditPagesPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const runs = useLatestRun(siteId);
  const ready = !!siteId && !runs.loading && !runs.error;

  const [statusFilter, setStatusFilter] = useState("all");
  // Same debounce as the Search explorers — typing must not fire a request per key.
  const [qInput, setQInput] = useState("");
  const [q, setQ] = useState("");
  useEffect(() => {
    const t = setTimeout(() => setQ((prev) => (prev === qInput.trim() ? prev : qInput.trim())), 400);
    return () => clearTimeout(t);
  }, [qInput]);

  const params = crawlListParams({ runId: runs.latestRun?.id, extra: { status: statusFilter !== "all" ? statusFilter : null, search: q } });
  const first = useApi<any>(ready ? `/sites/${siteId}/crawl/pages?${params}` : null);

  // Cursor pagination: first page through useApi, "Load more" appends by cursor.
  const [extra, setExtra] = useState<CrawlPageRow[]>([]);
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

  const rows: CrawlPageRow[] = [...(first.data?.data || []), ...extra];

  async function loadMore() {
    if (!siteId || !cursor || loadingMore) return;
    setLoadingMore(true);
    setMoreError(null);
    try {
      const next = await apiGet(`/sites/${siteId}/crawl/pages?${crawlListParams({ runId: runs.latestRun?.id, extra: { status: statusFilter !== "all" ? statusFilter : null, search: q }, cursor })}`);
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
    apiDownload(`/sites/${siteId}/exports/crawl.csv?type=pages`, "crawl-pages.csv").catch((e: any) =>
      setExportError(e?.message || "Export failed."),
    );
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<FileSearch size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site with the Insightistic plugin to audit its pages here."
      />
    );

  const columns: Column<CrawlPageRow>[] = [
    { key: "url", label: "URL", primary: true, render: (r) => <PathCell path={urlPath(r.url)} /> },
    {
      key: "status_code",
      label: "Status",
      align: "right",
      render: (r) =>
        r.status_code == null ? (
          <span className="text-muted">—</span>
        ) : (
          <span className={r.status_code >= 400 ? "font-semibold text-bad" : r.status_code >= 300 ? "text-warn" : "text-fg"}>
            {r.status_code}
          </span>
        ),
    },
    { key: "title", label: "Title", render: (r) => <span className="block max-w-[16rem] truncate text-sm" title={r.title ?? undefined}>{r.title || <span className="text-muted">—</span>}</span> },
    { key: "word_count", label: "Words", align: "right", render: (r) => num(r.word_count ?? undefined) },
    { key: "h1_count", label: "H1", align: "right", render: (r) => num(r.h1_count ?? undefined) },
    {
      key: "indexable",
      label: "Indexable",
      align: "right",
      render: (r) => (r.indexable ? <Badge tone="green">Yes</Badge> : <Badge tone="slate">No</Badge>),
    },
    { key: "response_ms", label: "ms", align: "right", render: (r) => (r.response_ms != null ? num(r.response_ms) : "—") },
  ];

  return (
    <div>
      <PageHeader title="Crawled pages" subtitle={`${site?.name || ""} · latest crawl · ${num(first.data?.meta?.total ?? undefined)} pages`} />

      {runs.error ? (
        <ErrorState message={runs.error} onRetry={runs.reload} />
      ) : runs.loading ? (
        <Spinner />
      ) : runs.runs.length === 0 ? (
        <EmptyState
          icon={<FileSearch size={20} />}
          title="Run your first crawl"
          hint="There are no crawled pages to show yet — run your first crawl to map this site."
          action={<RunBar siteId={siteId} run={null} onSettled={runs.reload} compact />}
        />
      ) : (
        <>
          <Card>
            <RunBar siteId={siteId} run={runs.latestRun} onSettled={() => { runs.reload(); first.reload(); }} compact />
          </Card>

          <Card className="mt-4">
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className={selectCls} aria-label="Page status">
                <option value="all">All statuses</option>
                <option value="ok">OK (2xx/3xx)</option>
                <option value="error">Errors (4xx/5xx)</option>
              </select>
              <input
                value={qInput}
                onChange={(e) => setQInput(e.target.value)}
                placeholder="Search URLs…"
                className="w-56 rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"
                aria-label="Search URLs"
              />
              <button
                onClick={exportCsv}
                className="ml-auto inline-flex items-center gap-2 rounded-xl border border-line bg-card px-3 py-2 text-sm font-semibold text-fg transition hover:border-brand/40 hover:bg-card2"
                title="Export the latest crawl's pages as CSV"
              >
                Export CSV
              </button>
            </div>
            {exportError && <p className="mb-3 text-xs text-bad">{exportError}</p>}

            {first.loading && !first.data ? (
              <Spinner />
            ) : first.error ? (
              <ErrorState message={first.error} onRetry={first.reload} />
            ) : (
              <>
                <ResponsiveTable
                  columns={columns}
                  rows={rows}
                  rowKey={(r, i) => `${r.url}-${i}`}
                  emptyMessage="No crawled pages match these filters."
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
        </>
      )}
    </div>
  );
}
