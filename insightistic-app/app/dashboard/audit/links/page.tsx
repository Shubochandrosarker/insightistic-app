"use client";
import { useEffect, useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiGet } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { PathCell } from "@/components/dashboard/search";
import { RunBar, crawlListParams, urlPath, useLatestRun } from "@/components/dashboard/audit";
import { FileSearch } from "lucide-react";

/**
 * Crawl links explorer (roadmap doc 12): broken internal links, redirecting
 * internal links, and the full internal/external link graph from the latest
 * run. No CSV export here — the backend only exports pages and issues.
 */

type CrawlLinkRow = {
  source_url: string;
  target_url: string;
  status_code: number | null;
  anchor: string | null;
  is_nofollow: boolean;
};

const LINK_TYPES = [
  { key: "broken", label: "Broken internal" },
  { key: "redirect", label: "Redirecting" },
  { key: "internal", label: "Internal" },
  { key: "external", label: "External" },
] as const;

type LinkType = (typeof LINK_TYPES)[number]["key"];

export default function AuditLinksPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const runs = useLatestRun(siteId);
  const ready = !!siteId && !runs.loading && !runs.error;

  const [type, setType] = useState<LinkType>("broken");

  const first = useApi<any>(ready ? `/sites/${siteId}/crawl/links?${crawlListParams({ runId: runs.latestRun?.id, extra: { type } })}` : null);

  // Cursor pagination: first page through useApi, "Load more" appends by cursor.
  const [extra, setExtra] = useState<CrawlLinkRow[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreError, setMoreError] = useState<string | null>(null);

  useEffect(() => {
    setExtra([]);
    setMoreError(null);
    setCursor(first.data?.meta?.next_cursor ?? null);
    setHasMore(!!first.data?.meta?.has_more);
  }, [first.data]);

  const rows: CrawlLinkRow[] = [...(first.data?.data || []), ...extra];

  async function loadMore() {
    if (!siteId || !cursor || loadingMore) return;
    setLoadingMore(true);
    setMoreError(null);
    try {
      const next = await apiGet(`/sites/${siteId}/crawl/links?${crawlListParams({ runId: runs.latestRun?.id, extra: { type }, cursor })}`);
      setExtra((prev) => [...prev, ...(next?.data || [])]);
      setCursor(next?.meta?.next_cursor ?? null);
      setHasMore(!!next?.meta?.has_more);
    } catch (e: any) {
      setMoreError(e?.message || "Couldn't load more rows.");
    } finally {
      setLoadingMore(false);
    }
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<FileSearch size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site with the Insightistic plugin to audit its links here."
      />
    );

  const columns: Column<CrawlLinkRow>[] = [
    { key: "source_url", label: "Source", primary: true, render: (r) => <PathCell path={urlPath(r.source_url)} /> },
    { key: "target_url", label: "Target", render: (r) => <PathCell path={urlPath(r.target_url)} /> },
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
    { key: "anchor", label: "Anchor", render: (r) => <span className="text-sm">{r.anchor || <span className="text-muted">—</span>}</span> },
    { key: "is_nofollow", label: "Nofollow", align: "right", render: (r) => (r.is_nofollow ? "Yes" : <span className="text-muted">—</span>) },
  ];

  return (
    <div>
      <PageHeader title="Links" subtitle={`${site?.name || ""} · latest crawl · internal, external, broken and redirecting links`} />

      {runs.error ? (
        <ErrorState message={runs.error} onRetry={runs.reload} />
      ) : runs.loading ? (
        <Spinner />
      ) : runs.runs.length === 0 ? (
        <EmptyState
          icon={<FileSearch size={20} />}
          title="Run your first crawl"
          hint="There are no crawled links to show yet — run your first crawl to map this site's link graph."
          action={<RunBar siteId={siteId} run={null} onSettled={runs.reload} compact />}
        />
      ) : (
        <>
          <Card>
            <RunBar siteId={siteId} run={runs.latestRun} onSettled={() => { runs.reload(); first.reload(); }} compact />
          </Card>

          <Card className="mt-4">
            <div className="mb-4 flex flex-wrap gap-2">
              {LINK_TYPES.map((t) => (
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

            {first.loading && !first.data ? (
              <Spinner />
            ) : first.error ? (
              <ErrorState message={first.error} onRetry={first.reload} />
            ) : (
              <>
                <ResponsiveTable
                  columns={columns}
                  rows={rows}
                  rowKey={(r, i) => `${r.source_url}-${r.target_url}-${i}`}
                  emptyMessage={
                    type === "broken"
                      ? "No broken internal links in the latest crawl — that's good news."
                      : type === "redirect"
                        ? "No redirecting internal links in the latest crawl."
                        : "No links of this type in the latest crawl."
                  }
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
