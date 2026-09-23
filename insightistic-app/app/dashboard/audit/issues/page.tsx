"use client";
import { useEffect, useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiDownload, apiGet, apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { PathCell } from "@/components/dashboard/search";
import { Button } from "@/components/ui/Button";
import { RunBar, SeverityBadge, crawlListParams, issueCodeLabel, urlPath, useLatestRun, type CrawlIssue } from "@/components/dashboard/audit";
import { timeAgo } from "@/lib/format";
import { FileSearch } from "lucide-react";

/**
 * Issue explorer (roadmap doc 12): every issue the crawler's issue engine
 * raised, filtered by lifecycle status / severity / category, with
 * ignore/reopen on the lifecycle (owner/admin server-side — surfaced with a
 * graceful message) and the issues CSV export.
 */

type IssueStatus = "open" | "resolved" | "ignored";

const ISSUE_STATUSES: { key: IssueStatus; label: string }[] = [
  { key: "open", label: "Open" },
  { key: "resolved", label: "Resolved" },
  { key: "ignored", label: "Ignored" },
];

const SEVERITIES = ["critical", "high", "medium", "low"];

const selectCls = "rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand";

export default function AuditIssuesPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const runs = useLatestRun(siteId);
  const ready = !!siteId && !runs.loading && !runs.error;

  const [status, setStatus] = useState<IssueStatus>("open");
  const [severity, setSeverity] = useState("all");
  // Category options come from the loaded rows — the backend doesn't expose a
  // category dictionary, and this way the select can never offer a dead option.
  const [category, setCategory] = useState("all");

  const issueParams = () =>
    crawlListParams({
      runId: runs.latestRun?.id,
      extra: { status, severity: severity !== "all" ? severity : null, category: category !== "all" ? category : null },
    });

  const first = useApi<any>(ready ? `/sites/${siteId}/crawl/issues?${issueParams()}` : null);

  // Cursor pagination: first page through useApi, "Load more" appends by cursor.
  const [extra, setExtra] = useState<CrawlIssue[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreError, setMoreError] = useState<string | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busyIssue, setBusyIssue] = useState<string | number | null>(null);

  useEffect(() => {
    setExtra([]);
    setMoreError(null);
    setCursor(first.data?.meta?.next_cursor ?? null);
    setHasMore(!!first.data?.meta?.has_more);
  }, [first.data]);

  const rows: CrawlIssue[] = [...(first.data?.data || []), ...extra];

  const categories = Array.from(new Set(rows.map((r) => r.category).filter(Boolean))).sort();

  async function loadMore() {
    if (!siteId || !cursor || loadingMore) return;
    setLoadingMore(true);
    setMoreError(null);
    try {
      const next = await apiGet(`/sites/${siteId}/crawl/issues?${crawlListParams({ runId: runs.latestRun?.id, extra: { status, severity: severity !== "all" ? severity : null, category: category !== "all" ? category : null }, cursor })}`);
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
    apiDownload(`/sites/${siteId}/exports/crawl.csv?type=issues`, "crawl-issues.csv").catch((e: any) =>
      setExportError(e?.message || "Export failed."),
    );
  }

  function setIssueStatus(issue: CrawlIssue, next: "open" | "ignored") {
    if (!siteId || busyIssue != null) return;
    setBusyIssue(issue.id);
    setActionError(null);
    apiPost(`/sites/${siteId}/crawl/issues/${issue.id}/status`, { status: next })
      .then(() => first.reload())
      .catch((e: any) => {
        if (e?.status === 403) setActionError("You don't have permission to change issue status — ask a site owner or admin.");
        else setActionError(e?.message || "Couldn't update the issue.");
      })
      .finally(() => setBusyIssue(null));
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<FileSearch size={20} />}
        title="No website yet"
        hint="Add a website to review its crawl issues here — any platform works."
      />
    );

  const columns: Column<CrawlIssue>[] = [
    {
      key: "issue_code",
      label: "Issue",
      primary: true,
      render: (r) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-fg">{issueCodeLabel(r.issue_code)}</p>
          <p className="truncate font-mono text-xs text-muted">{r.issue_code}</p>
        </div>
      ),
    },
    { key: "severity", label: "Severity", render: (r) => <SeverityBadge severity={r.severity} /> },
    { key: "category", label: "Category", render: (r) => <span className="text-sm capitalize">{String(r.category || "—").replace(/[_-]+/g, " ")}</span> },
    { key: "target_url", label: "Target", render: (r) => <PathCell path={urlPath(r.target_url)} /> },
    { key: "first_seen_at", label: "First seen", align: "right", render: (r) => timeAgo(r.first_seen_at) },
    { key: "last_seen_at", label: "Last seen", align: "right", render: (r) => timeAgo(r.last_seen_at) },
    {
      key: "actions",
      label: "",
      align: "right",
      render: (r) =>
        status === "open" ? (
          <Button variant="ghost" className="px-3 py-1.5 text-xs" disabled={busyIssue != null} onClick={() => setIssueStatus(r, "ignored")}>
            Ignore
          </Button>
        ) : status === "ignored" ? (
          <Button variant="ghost" className="px-3 py-1.5 text-xs" disabled={busyIssue != null} onClick={() => setIssueStatus(r, "open")}>
            Reopen
          </Button>
        ) : null,
    },
  ];

  return (
    <div>
      <PageHeader title="Issues" subtitle={`${site?.name || ""} · latest crawl · every finding with its evidence`} />

      {runs.error ? (
        <ErrorState message={runs.error} onRetry={runs.reload} />
      ) : runs.loading ? (
        <Spinner />
      ) : runs.runs.length === 0 ? (
        <EmptyState
          icon={<FileSearch size={20} />}
          title="Run your first crawl"
          hint="There are no issues to show yet — run your first crawl and Insightistic will collect technical findings."
          action={<RunBar siteId={siteId} run={null} onSettled={runs.reload} compact />}
        />
      ) : (
        <>
          <Card>
            <RunBar siteId={siteId} run={runs.latestRun} onSettled={() => { runs.reload(); first.reload(); }} compact />
          </Card>

          <Card className="mt-4">
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <div className="flex flex-wrap gap-2">
                {ISSUE_STATUSES.map((s) => (
                  <button
                    key={s.key}
                    type="button"
                    onClick={() => setStatus(s.key)}
                    className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${
                      status === s.key ? "bg-brand text-white" : "bg-card2 text-muted hover:text-fg"
                    }`}
                  >
                    {s.label}
                  </button>
                ))}
              </div>
              <select value={severity} onChange={(e) => setSeverity(e.target.value)} className={selectCls} aria-label="Severity">
                <option value="all">All severities</option>
                {SEVERITIES.map((s) => (
                  <option key={s} value={s}>
                    {s.charAt(0).toUpperCase() + s.slice(1)}
                  </option>
                ))}
              </select>
              <select value={category} onChange={(e) => setCategory(e.target.value)} className={selectCls} aria-label="Category">
                <option value="all">All categories</option>
                {categories.map((c) => (
                  <option key={c} value={c}>
                    {String(c).replace(/[_-]+/g, " ")}
                  </option>
                ))}
              </select>
              <button
                onClick={exportCsv}
                className="ml-auto inline-flex items-center gap-2 rounded-xl border border-line bg-card px-3 py-2 text-sm font-semibold text-fg transition hover:border-brand/40 hover:bg-card2"
                title="Export the latest crawl's issues as CSV"
              >
                Export CSV
              </button>
            </div>
            {exportError && <p className="mb-3 text-xs text-bad">{exportError}</p>}
            {actionError && <p className="mb-3 text-xs text-bad">{actionError}</p>}

            {first.loading && !first.data ? (
              <Spinner />
            ) : first.error ? (
              <ErrorState message={first.error} onRetry={first.reload} />
            ) : (
              <>
                <ResponsiveTable
                  columns={columns}
                  rows={rows}
                  rowKey={(r, i) => `${r.id}-${i}`}
                  emptyMessage={
                    status === "open"
                      ? "No open issues match these filters — the crawl came back clean."
                      : status === "resolved"
                        ? "No resolved issues recorded yet."
                        : "No ignored issues — nothing has been dismissed."
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
