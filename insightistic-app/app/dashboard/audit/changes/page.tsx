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
import { RunBar, SeverityBadge, crawlListParams, urlPath, useLatestRun, type CrawlIssueSeverity } from "@/components/dashboard/audit";
import { num } from "@/lib/format";
import { History } from "lucide-react";

/**
 * Change monitoring (roadmap doc 12 / Phase 8): the (page, field) diffs the
 * crawler logged between the latest completed run and the one before it.
 * Reuses the audit RunBar so a run refreshes changes, and the same cursor
 * pagination idiom as the other audit explorers. GSC impressions sit beside
 * every row because severity alone doesn't say how much a change matters —
 * a critical title change on a 4,000-impression page is a different story
 * than the same change on a page Google never shows.
 */

type CrawlChangeRow = {
  url: string;
  field: string;
  severity: CrawlIssueSeverity | string;
  old_value: string | null;
  new_value: string | null;
  impressions: number | null;
  created_at: string | null;
};

/** Human labels for the tracked fields; unknown codes pass through humanized. */
const FIELD_LABELS: Record<string, string> = {
  title: "Title",
  meta_description: "Meta description",
  canonical: "Canonical",
  indexability: "Indexability",
  status_code: "Status code",
  h1_count: "H1 count",
  content: "Content",
};

function fieldLabel(raw: string): string {
  return FIELD_LABELS[raw] ?? raw.replace(/[_-]+/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

const selectCls = "rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand";

/** One half of an Old → New cell: truncated, full value in the title tooltip, em-dash for null. */
function ChangeValue({ value }: { value: string | null }) {
  if (value == null || value === "")
    return (
      <span className="text-muted" title="No previous value">
        —
      </span>
    );
  return (
    <span className="block max-w-[11rem] truncate text-xs text-fg" title={value}>
      {value}
    </span>
  );
}

export default function AuditChangesPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const runs = useLatestRun(siteId);
  const ready = !!siteId && !runs.loading && !runs.error;

  // Changes only exist for completed runs — when the newest run is still
  // running, fall back to the newest completed one instead of showing an
  // empty list mid-crawl (the backend does the same when run_id is omitted).
  const latestCompleted = runs.runs.find((r) => r.status === "completed") ?? null;

  const [severity, setSeverity] = useState("all");
  const params = crawlListParams({ runId: latestCompleted?.id, extra: { severity: severity !== "all" ? severity : null } });
  const first = useApi<any>(ready ? `/sites/${siteId}/crawl/changes?${params}` : null);

  // Cursor pagination: first page through useApi, "Load more" appends by cursor.
  const [extra, setExtra] = useState<CrawlChangeRow[]>([]);
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

  const rows: CrawlChangeRow[] = [...(first.data?.data || []), ...extra];

  async function loadMore() {
    if (!siteId || !cursor || loadingMore) return;
    setLoadingMore(true);
    setMoreError(null);
    try {
      const next = await apiGet(
        `/sites/${siteId}/crawl/changes?${crawlListParams({ runId: latestCompleted?.id, extra: { severity: severity !== "all" ? severity : null }, cursor })}`,
      );
      setExtra((prev) => [...prev, ...(next?.data || [])]);
      setCursor(next?.meta?.next_cursor ?? null);
      setHasMore(!!next?.meta?.has_more);
    } catch (e: any) {
      setMoreError(e?.message || "Couldn't load more rows.");
    } finally {
      setLoadingMore(false);
    }
  }

  function refreshAll() {
    runs.reload();
    first.reload();
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<History size={20} />}
        title="No website yet"
        hint="Add a website to track content changes here — any platform works."
      />
    );

  const columns: Column<CrawlChangeRow>[] = [
    { key: "url", label: "URL", primary: true, render: (r) => <PathCell path={urlPath(r.url)} /> },
    { key: "field", label: "Field", render: (r) => <span className="text-sm font-medium">{fieldLabel(r.field)}</span> },
    {
      key: "change",
      label: "Old → New",
      render: (r) => (
        <div className="flex items-center gap-2">
          <ChangeValue value={r.old_value} />
          <span className="shrink-0 text-xs text-muted">→</span>
          <ChangeValue value={r.new_value} />
        </div>
      ),
    },
    { key: "severity", label: "Severity", render: (r) => <SeverityBadge severity={r.severity} /> },
    {
      key: "impressions",
      label: "GSC impressions",
      align: "right",
      render: (r) => <span className="text-sm">{r.impressions == null ? <span className="text-muted">—</span> : num(r.impressions)}</span>,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Changes"
        subtitle={`${site?.name || ""} · latest completed crawl · ${num(first.data?.meta?.total ?? undefined)} changes`}
      />

      <p className="mb-4 max-w-3xl text-sm text-muted">
        Changes compare the latest completed crawl with the one before it. Critical changes (indexability loss, status errors)
        also raise alerts. GSC impressions show each changed page&apos;s Google Search Console impressions — how much search
        traffic the change can actually affect.
      </p>

      {runs.error ? (
        <ErrorState message={runs.error} onRetry={runs.reload} />
      ) : runs.loading ? (
        <Spinner />
      ) : runs.runs.length === 0 ? (
        <EmptyState
          icon={<History size={20} />}
          title="Run your first crawl"
          hint="Change tracking starts at the second crawl — the first maps the site, every later run is diffed against the one before it."
          action={<RunBar siteId={siteId} run={null} onSettled={refreshAll} compact />}
        />
      ) : (
        <>
          <Card>
            <RunBar siteId={siteId} run={runs.latestRun} onSettled={refreshAll} compact />
          </Card>

          <Card className="mt-4">
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <select
                value={severity}
                onChange={(e) => setSeverity(e.target.value)}
                className={selectCls}
                aria-label="Change severity"
              >
                <option value="all">All severities</option>
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
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
                  rowKey={(r, i) => `${r.url}-${r.field}-${i}`}
                  emptyMessage="No changes detected in the latest run."
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
